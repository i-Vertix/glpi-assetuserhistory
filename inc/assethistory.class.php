<?php

/** @noinspection AutoloadingIssuesInspection */
/** @noinspection PhpUnused */

/**
 * -------------------------------------------------------------------------
 * AssetUserHistory plugin for GLPI
 * @copyright Copyright (C) 2023 by i-Vertix (https://i-vertix.com)
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/i-Vertix/assetuserhistory
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginAssetuserhistoryAssetHistory extends CommonDBRelation
{

    /**
     * @param $type
     * @return string
     */
    private static function getDisplayNameForAssetType($type): string
    {
        switch ($type) {
            case NetworkEquipment::getType():
                return "Network device";
            case Peripheral::getType():
                return "Device";
            default:
                return $type;
        }
    }

    /**
     * Count how many assets were assigned to this user
     * @param int $userId
     * @return int
     */
    public static function countHistoryItemsForUser(int $userId): int
    {
        global $DB;

        $query = "SELECT h.assets_id, h.assets_type
                FROM glpi_plugin_assetuserhistory_history as h
                INNER JOIN glpi_users as u ON (h.`users_id` = u.`id`)
                WHERE h.users_id = $userId";
        $results = $DB->doQuery($query)->fetch_all(MYSQLI_ASSOC);

        if (count($results) === 0) return 0;

        // CREATE HASHMAP FOR ASSETS BY TYPE TO ACCESS ASSET INDEXES AND IDS FASTER
        $assetsByType = [];
        foreach ($results as $index => $res) {
            if (!isset($assetsByType[$res["assets_type"]])) {
                $assetsByType[$res["assets_type"]] = [];
            }
            if (!isset($assetsByType[$res["assets_type"]][$res["assets_id"]])) {
                $assetsByType[$res["assets_type"]][$res["assets_id"]] = [];
            }
            $assetsByType[$res["assets_type"]][$res["assets_id"]][] = $index;
        }

        // THIS IS WHERE THE MAGIC HAPPENS
        foreach ($assetsByType as $itemType => $indexesByIds) {
            // GET ASSET MODEL ITEM BY TYPE
            $model = getItemForItemtype($itemType);
            if (!$model) continue;

            // EXTRACT IDS FROM HASHMAP FOR CURRENT TYPE
            $ids = array_keys($indexesByIds);

            // CHECK IF USER HAS PERMISSION TO VIEW ASSET TYPE
            if (!$model::canView()) {
                // USER HAS NO PERMISSION TO VIEW ASSET TYPE - REMOVE ALL FROM LIST
                $allIndexes = array_merge([], ...$indexesByIds);
                foreach ($allIndexes as $index) {
                    unset($results[$index]);
                }
                continue;
            }

            // CREATE NEW INSTANCE OF ASSET MODEL (USED TO FIND ASSETS BY IDS)
            $obj = new $model;

            // GET EXISTING ASSETS BY IDS
            $items = array_map(static function ($i) use ($model) {
                $o = new $model;
                $o->getFromResultSet($i);
                return $o;
            }, $obj->find(['id' => $ids]));

            // CHECK PERMISSIONS FOR PRESENT ASSETS
            foreach ($items as $i) {
                if (!$i->canViewItem()) {
                    // USER HAS NO PERMISSION - REMOVE ASSETS FROM LIST
                    foreach ($indexesByIds[$i->getID()] as $index) {
                        unset($results[$index]);
                    }
                }
            }
        }

        return count($results);
    }

    /**
     * Count how many times this asset got assigned to a user
     * @param int $assetId
     * @param string $assetType
     * @return int
     */
    public static function countHistoryItemsForAsset(int $assetId, string $assetType): int
    {
        global $DB;

        $assetTable = getTableForItemType($assetType);

        $query = "SELECT h.users_id
                FROM glpi_plugin_assetuserhistory_history as h
                INNER JOIN $assetTable as a ON h.assets_id = a.id
                WHERE h.assets_id = $assetId and h.assets_type = '$assetType'";

        $results = $DB->doQuery($query)->fetch_all(MYSQLI_ASSOC);

        if (count($results) === 0) return 0;

        // GET USER MODEL
        $model = new User();
        $indexesByUserIds = [];
        foreach ($results as $index => $res) {
            if (!isset($indexesByUserIds["users_id"])) {
                $indexesByUserIds["users_id"] = [];
            }
            $indexesByUserIds["users_id"][] = $index;
        }
        $userIds = array_keys($indexesByUserIds);
        // GET ALL USERS BY IDS
        $userArr = $model->find(["id" => $userIds]);
        foreach ($userArr as $arr) {
            // CHECK PERMISSIONS FOR EVERY PRESENT USER IN HISTORY LIST ONCE
            $obj = new User();
            $obj->getFromResultSet($arr);
            if (!$obj->canViewItem()) {
                // CURRENT USER CAN NOT VIEW HISTORY USER -> REMOVE FROM LIST
                foreach ($indexesByUserIds[$obj->getID()] as $index) {
                    unset($results[$index]);
                }
            }
        }
        unset($userArr, $obj);

        return count($results);
    }

    /**
     * Displays a table with assets assigned to this user
     *
     * @param User $item
     * @return bool
     */
    public static function showForUser(User $item): bool
    {
        global $DB;

        $id = $item->getID();

        $order = isset($_GET['filters']['order']) && ($_GET['filters']['order'] === 'ASC') ? $_GET['filters']['order'] : 'DESC';
        $sort = !empty($_GET['filters']['sort']) ? $_GET['filters']['sort'] : 'assigned';
        $start = (isset($_REQUEST['start']) ? (int)$_REQUEST['start'] : 0);
        $displayNum = (int)($_SESSION['glpilist_limit'] ?? 15);

        // INVERT ORDER FOR QUERY BECAUSE SORT FIELD IS INVERTED (TO INVERT NULL VALUES)
        $orderForQuery = $order === "ASC" ? "DESC" : "ASC";

        $orderBy = $sort === "assigned" ? "isnull({$sort}) {$orderForQuery}, -{$sort} {$orderForQuery}" : "-{$sort} {$orderForQuery}";

        $query = "SELECT
                h.assigned as assigned,
                h.revoked as revoked,
                h.assets_type,
                h.assets_id
            FROM
                glpi_plugin_assetuserhistory_history h
            WHERE
                h.users_id = $id
            ORDER BY {$orderBy}";
        $results = $DB->doQuery($query)->fetch_all(MYSQLI_ASSOC);

        $isFiltered = false;

        if (count($results) > 0) {

            // FILTER
            if (!empty($_GET['filters']) && (isset($_GET['filters']['active']) && $_GET['filters']['active'] === '1')) {
                $isFiltered = true;
            }

            if (!empty($_GET['filters']['assets_type'])) {
                $typeFilter = $_GET['filters']['assets_type'];
                $results = array_values(array_filter($results, static function ($res) use ($typeFilter) {
                    return str_contains(strtoupper($res["assets_type"]), strtoupper($typeFilter));
                }));
            }

            // CREATE HASHMAP FOR ASSETS BY TYPE TO ACCESS ASSET INDEXES AND IDS FASTER
            $assetsByType = [];
            foreach ($results as $index => $res) {
                if (!isset($assetsByType[$res["assets_type"]])) {
                    $assetsByType[$res["assets_type"]] = [];
                }
                if (!isset($assetsByType[$res["assets_type"]][$res["assets_id"]])) {
                    $assetsByType[$res["assets_type"]][$res["assets_id"]] = [];
                }
                $assetsByType[$res["assets_type"]][$res["assets_id"]][] = $index;
            }

            // THIS IS WHERE THE MAGIC HAPPENS
            foreach ($assetsByType as $itemType => $indexesByIds) {
                // GET ASSET MODEL ITEM BY TYPE
                $model = getItemForItemtype($itemType);
                if (!$model) continue;

                // EXTRACT IDS FROM HASHMAP FOR CURRENT TYPE
                $ids = array_keys($indexesByIds);

                // CHECK IF USER HAS PERMISSION TO VIEW ASSET TYPE
                if (!$model::canView()) {
                    // USER HAS NO PERMISSION TO VIEW ASSET TYPE - REMOVE ALL FROM LIST
                    $allIndexes = array_merge([], ...$indexesByIds);
                    foreach ($allIndexes as $index) {
                        unset($results[$index]);
                    }
                    continue;
                }

                // CREATE NEW INSTANCE OF ASSET MODEL (USED TO FIND ASSETS BY IDS)
                $obj = new $model;

                // GET EXISTING ASSETS BY IDS
                $items = array_map(static function ($i) use ($model) {
                    $o = new $model;
                    $o->getFromResultSet($i);
                    return $o;
                }, $obj->find(['id' => $ids]));

                // CHECK PERMISSIONS FOR PRESENT ASSETS
                foreach ($items as $i) {
                    if (!$i->canViewItem()) {
                        // USER HAS NO PERMISSION - REMOVE ASSETS FROM LIST
                        foreach ($indexesByIds[$i->getID()] as $index) {
                            unset($results[$index]);
                        }
                    } else {
                        // USER HAS ACCESS TO ASSET - SET REAL NAME TO LIST ITEM
                        foreach ($indexesByIds[$i->getID()] as $index) {
                            $results[$index]["assets_name"] = $i->getName();
                        }
                    }
                }
            }

            if (!empty($_GET['filters']['assets_name'])) {
                $nameFilter = $_GET['filters']['assets_name'];
                $results = array_filter($results, static function ($res) use ($nameFilter) {
                    return str_contains(strtoupper($res["assets_name"]), strtoupper($nameFilter));
                });
            }

            $results = array_values($results);
            $total = count($results);
            $results = array_splice($results, $start, $displayNum);
        }

        if (!$isFiltered && count($results) === 0) {
            echo "<p class='center b'>" . __('No item found') . "</p>";
            return true;
        }

        // OLD CODE TO SET REAL NAME FOR ASSET BEFORE PERMISSION CHECK
        /*
        if ($isFiltered || count($results) > 0) {
            // AFTER PREPARING LIST ITEMS TO SHOW -> LOAD ASSET IDS AND NAMES AND REPLACE IN LIST ITEMS
            // REMOVE ASSET IDS OF ASSETS WHICH GOT ALREADY DELETED AND USE NAME OF HISTORY DATA
            $typeGroups = [];
            // COLLECT IDS BY ASSET TYPE
            foreach ($results as $res) {
                if (!isset($typeGroups[$res["assets_type"]])) {
                    $typeGroups[$res["assets_type"]] = [];
                }
                $typeGroups[$res["assets_type"]][] = $res["assets_id"];
            }
            // QUERY ASSET IDS AND NAMES BY ASSET TYPE AND IDS (ONE QUERY PER TYPE)
            foreach ($typeGroups as $type => $ids) {
                $query = "select a.id, a.name from " . getTableForItemType($type) . " as a where a.id in (" . implode(",", $ids) . ")";
                $assets = $DB->query($query)->fetch_all(MYSQLI_ASSOC);
                $typeGroups[$type] = [];
                // PREP HASHMAP
                foreach ($assets as $a) {
                    $typeGroups[$type][$a["id"]] = $a["name"];
                }
            }
            foreach ($results as &$result) {
                // CHECK PERMISSIONS TO VIEW ASSET TYPE
                if (isset($typeGroups[$result["assets_type"]][$result["assets_id"]]) && getItemForItemtype($result["assets_type"])::canView()) {
                    // ASSIGN CURRENT ASSET NAME BECAUSE ASSET STILL PRESENT
                    $result["assets_name"] = $typeGroups[$result["assets_type"]][$result["assets_id"]];
                } else {
                    // REMOVE ASSET ID TO PREVENT HREF IN LIST ITEM BECAUSE ASSET NO LONGER PRESENT
                    $result["assets_id"] = null;
                }
            }
            unset($result);
        } else {
            echo "<p class='center b'>" . __('No item found') . "</p>";
            return true;
        }
        */


        echo "<div class='spaced'>";
        Html::printAjaxPager('', $start, $total);
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover table-striped border my-2'>";
        $header_begin = "<tr>";
        $header_end = "<th>" . __('Name') . "</th>";
        $header_end .= "<th>" . __('Type') . "</th>";

        // ASSIGNED
        $header_end .= "<th";
        if ($sort === "assigned") {
            $header_end .= " class='order_" . $order . "'>";
        } else {
            $header_end .= ">";
        }
        $header_end .= "<a href='javascript:handleSort(\"assigned\", \"" . ($order === "ASC" ? "DESC" : "ASC") . "\")'>" . ucfirst(__('Assigned')) . "</th>";

        // REVOKED
        $header_end .= "<th";
        if ($sort === "revoked") {
            // INVERT ARROW DUE TO INVERTED SORT ORDER IN QUERY
            $header_end .= " class='order_" . $order . "'>";
        } else {
            $header_end .= ">";
        }
        $header_end .= "<a href='javascript:handleSort(\"revoked\", \"" . ($order === "ASC" ? "DESC" : "ASC") . "\")'>" . __('Revoked', 'assetuserhistory') . "</th>";

        $header_end .= "<th>
                <button class='btn btn-sm show_log_filters " . ($isFiltered ? "btn-secondary active" : "btn-outline-secondary") . "'>
                    <i class='fas fa-filter'></i>
                    <span class='d-none d-xl-block'>" . __('Filter') . "</span>
                </button>
                <span class='log_history_filter_row'>
                <input type='hidden' name='filters[order]' value='" . $order . "'>
                <input type='hidden' name='filters[sort]' value='" . $sort . "'>
                </span>
                </th>";
        $header_end .= "</tr>";
        echo $header_begin . $header_end;

        if ($isFiltered) {
            echo "<tr class='log_history_filter_row'>
                    <td>
                        <input type='text' class='form-control' name='filters[assets_name]' value='" . ($nameFilter ?? '') . "'>
                    </td>
                    <td>
                        <input type='text' class='form-control' name='filters[assets_type]' value='" . ($typeFilter ?? '') . "'>
                    </td>
                    <td></td>
                    <td></td>
                    <td>
                        <input type='hidden' name='filters[active]' value='1'>
                    </td>
                </tr>";
        }

        foreach ($results as $res) {
            echo "<tr class='tab_bg_1'>";
            if ($res["assets_id"]) {
                $link = getItemForItemtype($res["assets_type"])::getFormURLWithID($res["assets_id"]);
                Session::addToNavigateListItems($res["assets_type"], $res["assets_id"]);
                echo '<td><a href=' . $link . '>' . ($res['assets_name'] ?? "") . '</a></td>';
            } else {
                echo '<td>' . $res["assets_name"] . '</td>';
            }
            echo "<td>" . __(self::getDisplayNameForAssetType($res["assets_type"])) . "</td>";
            echo "<td>" . ($res["assigned"] === null ? "?" : Html::convDateTime($res["assigned"], null, true)) . "</td>";
            echo "<td>" . Html::convDateTime($res["revoked"], null, true) . "</td>";
            echo "<td></td>";
            echo "</tr>\n";
        }

        echo "<tfoot>";
        echo $header_begin . $header_end;
        echo "</tfoot>";
        echo "</table>";
        echo "</div>";
        Html::printAjaxPager('', $start, $total);
        echo "</div>";

        // WORKAROUND FOR COLUMN SORT TOGETHER WITH FILTERS
        // TRIGGER ENTER PRESSED ON INPUT WHEN SORT CHANGED TO FIRE handleFilterChange() of "/js/log_filters.js"
        // STRANGE BUT handleFilterChange() IS NOT DIRECTLY CALLABLE
        // BECAUSE OF THAT:
        // SORT AND ORDER ARE HIDDEN FILTERS SO THEY GET SENT ALONG WITH OTHER FILTERS ON handleFilterChange()
        echo '
<script type="text/javascript">
function handleSort(field, order) {
    $("input[name=\'filters[sort]\']").val(field);
    const orderField = $("input[name=\'filters[order]\']");
    orderField.val(order);
    const e = jQuery.Event("keypress");
    e.keyCode = e.which = 13;
    e.key = "Enter";
    orderField.trigger(e);
}        
</script>
        ';

        return true;
    }

    /**
     * Displays a table users linked to this asset
     *
     * @param User $item
     * @return bool
     */
    public static function showForAsset(CommonDBTM $item): bool
    {
        global $DB;

        $id = $item->getID();
        $type = $item::getType();

        $order = isset($_GET['filters']['order']) && ($_GET['filters']['order'] === 'ASC') ? $_GET['filters']['order'] : 'DESC';
        $sort = !empty($_GET['filters']['sort']) ? $_GET['filters']['sort'] : 'assigned';
        $start = (isset($_REQUEST['start']) ? (int)$_REQUEST['start'] : 0);
        $displayNum = (int)($_SESSION['glpilist_limit'] ?? 15);

        // INVERT ORDER FOR QUERY BECAUSE SORT FIELD IS INVERTED (TO INVERT NULL VALUES)
        $orderForQuery = $order === "ASC" ? "DESC" : "ASC";

        $orderBy = $sort === "assigned" ? "isnull({$sort}) {$orderForQuery}, -{$sort} {$orderForQuery}" : "-{$sort} {$orderForQuery}";

        $query = "SELECT
                u.id as users_id,
                u.name as users_name,
                h.assigned as assigned,
                h.revoked as revoked
            FROM
                glpi_plugin_assetuserhistory_history h
                inner join glpi_users u on u.id = h.users_id    
            WHERE
                h.assets_id = $id
                and h.assets_type = '$type'
            ORDER BY {$orderBy}";

        $results = $DB->doQuery($query)->fetch_all(MYSQLI_ASSOC);

        $isFiltered = false;
        $total = 0;

        if (count($results) > 0) {

            // FILTER
            if (!empty($_GET['filters']) && (isset($_GET['filters']['active']) && $_GET['filters']['active'] === '1')) {
                $isFiltered = true;
                $nameFilter = $_GET['filters']['users_name'] ?? "";
                if (!empty($nameFilter)) {
                    $results = array_values(array_filter($results, static function ($res) use ($nameFilter) {
                        return (
                        (empty($nameFilter) || str_contains(strtoupper($res["assets_name"]), strtoupper($nameFilter)))
                        );
                    }));
                }
            }

            // GET USER MODEL
            $model = new User();
            $indexesByUserIds = [];
            foreach ($results as $index => $res) {
                if (!isset($indexesByUserIds["users_id"])) {
                    $indexesByUserIds["users_id"] = [];
                }
                $indexesByUserIds["users_id"][] = $index;
            }
            $userIds = array_keys($indexesByUserIds);
            // GET ALL USERS BY IDS
            $userArr = $model->find(["id" => $userIds]);
            foreach ($userArr as $arr) {
                // CHECK PERMISSIONS FOR EVERY PRESENT USER IN HISTORY LIST ONCE
                $obj = new User();
                $obj->getFromResultSet($arr);
                if (!$obj->canViewItem()) {
                    // CURRENT USER CAN NOT VIEW HISTORY USER -> REMOVE ALL ITEMS FROM LIST WITH USER ID
                    foreach ($indexesByUserIds[$obj->getID()] as $index) {
                        unset($results[$index]);
                    }
                }
            }
            unset($userArr, $obj);

            $results = array_values($results);
            $total = count($results);
            $results = array_splice($results, $start, $displayNum);
        }

        if (!$isFiltered && count($results) === 0) {
            echo "<p class='center b'>" . __('No item found') . "</p>";
            return true;
        }


        echo "<div class='spaced'>";
        Html::printAjaxPager('', $start, $total);
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover table-striped border my-2'>";
        $header_begin = "<tr>";
        $header_end = "<th>" . __('Login') . "</th>";

        // ASSIGNED
        $header_end .= "<th";
        if ($sort === "assigned") {
            // INVERT ARROW DUE TO INVERTED SORT ORDER IN QUERY
            $header_end .= " class='order_" . $order . "'>";
        } else {
            $header_end .= ">";
        }
        $header_end .= "<a href='javascript:handleSort(\"assigned\", \"" . ($order === "ASC" ? "DESC" : "ASC") . "\")'>" . ucfirst(__('Assigned')) . "</th>";

        // REVOKED
        $header_end .= "<th";
        if ($sort === "revoked") {
            // INVERT ARROW DUE TO INVERTED SORT ORDER IN QUERY
            $header_end .= " class='order_" . $order . "'>";
        } else {
            $header_end .= ">";
        }
        $header_end .= "<a href='javascript:handleSort(\"revoked\", \"" . ($order === "ASC" ? "DESC" : "ASC") . "\")'>" . __('Revoked', 'assetuserhistory') . "</th>";

        $header_end .= "<th>
                <button class='btn btn-sm show_log_filters " . ($isFiltered ? "btn-secondary active" : "btn-outline-secondary") . "'>
                    <i class='fas fa-filter'></i>
                    <span class='d-none d-xl-block'>" . __('Filter') . "</span>
                </button>
                <span class='log_history_filter_row'>
                <input type='hidden' name='filters[order]' value='" . $order . "'>
                <input type='hidden' name='filters[sort]' value='" . $sort . "'>
                </span>
                </th>";
        $header_end .= "</tr>";
        echo $header_begin . $header_end;

        if ($isFiltered) {
            echo "<tr class='log_history_filter_row'>
                    <td>
                        <input type='text' class='form-control' name='filters[assets_name]' value='" . ($nameFilter ?? '') . "'>
                    </td>
                    <td></td>
                    <td></td>
                    <td>
                        <input type='hidden' name='filters[active]' value='1'>
                    </td>
                </tr>";
        }

        foreach ($results as $res) {
            echo "<tr class='tab_bg_1'>";
            if ($res["users_id"]) {
                $link = User::getFormURLWithID($res["users_id"]);
                Session::addToNavigateListItems(User::getType(), $res["users_id"]);
                echo '<td><a href=' . $link . '>' . $res['users_name'] . '</a></td>';
            } else {
                echo '<td>' . $res["users_name"] . '</td>';
            }
            echo "<td>" . ($res["assigned"] === null ? "?" : Html::convDateTime($res["assigned"], null, true)) . "</td>";
            echo "<td>" . Html::convDateTime($res["revoked"], null, true) . "</td>";
            echo "<td></td>";
            echo "</tr>\n";
        }

        echo "<tfoot>";
        echo $header_begin . $header_end;
        echo "</tfoot>";
        echo "</table>";
        echo "</div>";
        Html::printAjaxPager('', $start, $total);
        echo "</div>";

        // WORKAROUND FOR COLUMN SORT TOGETHER WITH FILTERS
        // TRIGGER ENTER PRESSED ON INPUT WHEN SORT CHANGED TO FIRE handleFilterChange() of "/js/log_filters.js"
        // STRANGE BUT handleFilterChange() IS NOT DIRECTLY CALLABLE
        // BECAUSE OF THAT:
        // SORT AND ORDER ARE HIDDEN FILTERS SO THEY GET SENT ALONG WITH OTHER FILTERS ON handleFilterChange()
        echo '
<script type="text/javascript">
function handleSort(field, order) {
    $("input[name=\'filters[sort]\']").val(field);
    const orderField = $("input[name=\'filters[order]\']");
    orderField.val(order);
    const e = jQuery.Event("keypress");
    e.keyCode = e.which = 13;
    e.key = "Enter";
    orderField.trigger(e);
}        
</script>
        ';

        return true;
    }

    /**
     * @see CommonGLPI::getTabNameForItem()
     **/
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array|string
    {
        switch ($item::getType()) {
            case 'User' :
                if (!$withtemplate) {
                    $nb = 0;
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        $nb = self::countHistoryItemsForUser($item->getID());
                    }

                    return array(
                        1 => self::createTabEntry(__("Asset history", 'assetuserhistory'), $nb)
                    );
                }
                break;
            default:
                if (!$withtemplate) {
                    $nb = 0;
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        $nb = self::countHistoryItemsForAsset($item->getID(), $item::getType());
                    }

                    return array(
                        1 => self::createTabEntry(__("User history", 'assetuserhistory'), $nb)
                    );
                }
                break;
        }

        return '';
    }

    /**
     * @see CommonGLPI::displayTabContentForItem()
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item::getType() === User::getType()) {
            /** @noinspection PhpParamsInspection */
            self::showForUser($item);
        } else {
            /** @noinspection PhpParamsInspection */
            self::showForAsset($item);
        }

        return true;
    }
}
