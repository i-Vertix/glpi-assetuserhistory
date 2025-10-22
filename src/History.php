<?php

/**
 * -------------------------------------------------------------------------
 * Asset-User History plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This file is part of Asset-User History plugin for GLPI.
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
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by i-Vertix/PGUM.
 * @license   MIT https://opensource.org/license/mit
 * @link      https://github.com/i-Vertix/glpi-assetuserhistory
 * -------------------------------------------------------------------------
 */

/** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

namespace GlpiPlugin\Assetuserhistory;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use Html;
use Migration;
use ProfileRight;
use Session;
use User;

class History extends CommonDBTM
{

    public $no_form_page = true;
    public $auto_message_on_action = false;
    public static $rightname = 'plugin_assetuserhistory_history';
    public const VIEW_USER_HISTORY = 1;
    public const VIEW_ASSET_HISTORY = 2;

    /**
     * @param Migration $migration
     * @param string[] $injections
     * @return bool
     */
    public static function install(Migration $migration, array $injections): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        self::uninstallTriggers();

        // rename table
        $migration->renameTable("glpi_plugin_assetuserhistory_history", $table);

        if (!$DB->tableExists($table)) {
            // INSTALL
            $query = <<<SQL
                create table if not exists `{$table}` (
                    `id` int {$default_key_sign} not null primary key auto_increment,
                    `users_id` int {$default_key_sign} not null,
                    `items_id` int {$default_key_sign} not null,
                    `itemtype` varchar(255) not null,
                    `assigned` timestamp null,
                    `revoked` timestamp null,
                    key ´item´ (`itemtype`, `items_id`),
                    key `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
            SQL;

            $DB->doQuery($query);
        } else {
            // UPGRADE 1.2.0
            $migration->changeField($table, "assets_id", "items_id", "int {$default_key_sign} not null");
            $migration->changeField($table, "assets_type", "itemtype", "varchar(255) not null");
            $migration->addKey($table, ["items_id", "itemtype"], "item");
            $migration->addKey($table, "users_id", "users_id");
        }

        // check if rights are already set
        $setDefaultRights = (int)($DB->request([
                "COUNT" => "cnt",
                "FROM" => ProfileRight::getTable(),
                "WHERE" => [
                    "name" => self::$rightname,
                ]
            ])->current()["cnt"] ?? 0) === 0;

        // add right where missing (initially without permissions)
        $migration->addRight(self::$rightname, 0, []);

        // set default permissions based on asset/user if rights are new
        if ($setDefaultRights) {
            $injections = array_values(array_filter($injections, static fn($i) => $i !== "User"));
            $requiredRights = array_fill_keys(array_map(static fn($i) => $i::$rightname ?? "", $injections), READ);
            if (!empty($requiredRights)) {
                $migration->giveRight(
                    self::$rightname,
                    self::VIEW_USER_HISTORY,
                    $requiredRights,
                );
            }
            $migration->giveRight(
                self::$rightname,
                self::VIEW_ASSET_HISTORY,
                [User::$rightname => READ]
            );
        }

        return true;
    }

    /**
     * @param string $itemtype
     * @return bool
     */
    public static function installTrigger(string $itemtype): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();

        $table = self::getTable();
        $itemTable = getTableForItemType($itemtype);
        if (empty($itemTable) || !$DB->tableExists($itemTable)) return false;

        $name = strtolower($itemtype);

        $query = <<<SQL
            create or replace trigger `plugin_assetuserhistory_{$name}_add`
                after insert
                on `{$itemTable}`
                for each row
            begin
                DECLARE aType varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                SET aType = '{$itemtype}';
                if (NEW.users_id <> 0 and NEW.users_id is not null and NEW.users_id is not null) then
                    insert into {$table} (users_id, items_id, itemtype, assigned)
                    values (NEW.users_id, NEW.id, aType, NOW());
                end if;
            end;
        SQL;
        $DB->doQuery($query);

        $query = <<<SQL
            create or replace trigger plugin_assetuserhistory_{$name}_update
                after update
                on `{$itemTable}`
                for each row
            begin
                DECLARE aType varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                SET aType = '{$itemtype}';
                if (NEW.users_id <> OLD.users_id) then
                
                    update {$table}
                    set revoked = NOW()
                    where users_id = OLD.users_id
                    and items_id = NEW.id
                    and itemtype = itemtype
                    and revoked is null;
            
                    if (NEW.users_id <> 0) then
                        insert into {$table} (users_id, items_id, itemtype, assigned)
                        values (NEW.users_id, NEW.id, aType, NOW());
                    end if;
                end if;
            end;
        SQL;
        $DB->doQuery($query);

        Session::addMessageAfterRedirect("✅ Enabled user-history for " . $itemtype);

        return true;
    }

    /**
     * Imports the current items associated with the specified item type into the database.
     *
     * @param string $itemtype The type of item to be imported.
     * @return bool Returns true if the import process completes successfully.
     */
    public static function importCurrent(string $itemtype): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();
        $itemTable = getTableForItemType($itemtype);

        $DB->insert(self::getTable(), new QuerySubQuery(
            [
                "SELECT" => [
                    new QueryExpression('null', 'id'),
                    "$itemTable.users_id as users_id",
                    "$itemTable.id as items_id",
                    new QueryExpression($DB::quoteValue($itemtype), 'itemtype'),
                    new QueryExpression('null', 'assigned'),
                    new QueryExpression('null', 'revoked'),
                ],
                "FROM" => $itemTable,
                "LEFT JOIN" => [
                    $table => [
                        "ON" => [
                            $table => "items_id",
                            $itemTable => "id",
                            ["AND" => ["$table.itemtype" => "'$itemtype'"]]
                        ]
                    ]
                ],
                "WHERE" => [
                    "$itemTable.users_id" => ["<>", 0],
                    "NOT" => ["$itemTable.users_id" => null],
                    "$itemTable.is_deleted" => 0,
                    "$table.id" => null
                ]
            ]
        ));
        return true;
    }

    /**
     * @param Migration $migration
     * @return bool
     */
    public static function uninstall(Migration $migration): bool
    {
        $table = self::getTable();
        $migration->dropTable($table);
        ProfileRight::deleteProfileRights([self::$rightname]);
        return true;
    }

    /**
     * Uninstall triggers related to plugin from the database.
     * @return bool
     */
    public static function uninstallTriggers(): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $query = [
            "SELECT" => ["TRIGGER_NAME"],
            "FROM" => "information_schema.TRIGGERS",
            "WHERE" => [
                "OR" => [
                    [
                        "TRIGGER_NAME" => ["LIKE", "plugin_assetuserhistory_%_add"]
                    ],
                    [
                        "TRIGGER_NAME" => ["LIKE", "plugin_assetuserhistory_%_update"]
                    ],
                ],
                "TRIGGER_SCHEMA" => $DB->dbdefault
            ]
        ];

        $iterator = $DB->request($query);
        foreach ($iterator as $res) {
            $DB->doQuery("DROP TRIGGER IF EXISTS " . $res["TRIGGER_NAME"]);
        }
        return true;
    }

    /**
     * @param int $nb
     * @return string
     */
    public static function getTypeName($nb = 0): string
    {
        return __s('View history', 'assetuserhistory');
    }

    /**
     * @return string
     */
    public static function getIcon(): string
    {
        return 'ti ti-replace-user';
    }

    /**
     * @param string $interface
     * @return array
     */
    public function getRights($interface = 'central'): array
    {
        return [
            self::VIEW_USER_HISTORY => __s('For asset', 'assetuserhistory'),
            self::VIEW_ASSET_HISTORY => __s('For user', 'assetuserhistory'),
        ];
    }

    /**
     * Count how many assets were assigned to this user
     * @param int $userId
     * @return int
     */
    public static function countHistoryItemsForUser(int $userId): int
    {
        global $DB;

        $table = self::getTable();
        $query = [
            "SELECT" => [
                "$table.items_id",
                "$table.itemtype",
            ],
            "FROM" => $table,
            "INNER JOIN" => [
                User::getTable() => [
                    "ON" => [
                        $table => "users_id",
                        User::getTable() => "id"
                    ]
                ]
            ],
            "WHERE" => [
                "$table.users_id" => $userId
            ]
        ];
        $resultIterator = $DB->request($query);

        if ($resultIterator->count() === 0) return 0;

        // CREATE HASHMAP FOR ASSETS BY TYPE TO ACCESS ASSET INDEXES AND IDS FASTER
        $countMap = [];
        foreach ($resultIterator as $res) {
            if (!isset($countMap[$res["itemtype"]])) {
                $countMap[$res["itemtype"]] = [];
            }
            if (!isset($countMap[$res["itemtype"]][$res["items_id"]])) {
                $countMap[$res["itemtype"]][$res["items_id"]] = 0;
            }
            $countMap[$res["itemtype"]][$res["items_id"]]++;
        }

        // THIS IS WHERE THE MAGIC HAPPENS
        foreach ($countMap as $itemType => $countByItemsId) {
            // GET ASSET MODEL ITEM BY TYPE
            $model = getItemForItemtype($itemType);
            if (!$model) continue;

            // EXTRACT IDS FROM HASHMAP FOR CURRENT TYPE
            $itemIds = array_keys($countByItemsId);

            // CHECK IF USER HAS PERMISSION TO VIEW ASSET TYPE
            if (!$model::canView()) {
                // USER HAS NO PERMISSION TO VIEW ASSET TYPE - REMOVE ALL FROM LIST
                unset($countMap[$itemType]);
                continue;
            }

            // CREATE NEW INSTANCE OF ASSET MODEL (USED TO FIND ASSETS BY IDS)
            $obj = new $model;

            // GET EXISTING ASSETS BY IDS
            $items = array_map(static function ($i) use ($model) {
                $o = new $model;
                $o->getFromResultSet($i);
                return $o;
            }, $obj->find(['id' => $itemIds]));

            // CHECK PERMISSIONS FOR PRESENT ASSETS
            foreach ($items as $i) {
                if (!$i->canViewItem()) {
                    // USER HAS NO PERMISSION - REMOVE ASSETS FROM LIST
                    unset($countMap[$itemType][$i->getID()]);
                }
            }
        }

        // get complete counts
        $count = 0;
        foreach ($countMap as $countByItemsId) {
            foreach ($countByItemsId as $c) {
                $count += $c;
            }
        }
        return $count;
    }

    /**
     * Count how many times this asset got assigned to a user
     * @param int $itemsId
     * @param string $itemtype
     * @return int
     */
    public static function countHistoryItemsForAsset(int $itemsId, string $itemtype): int
    {
        global $DB;

        $itemTable = getTableForItemType($itemtype);
        $table = self::getTable();

        $query = [
            "SELECT" => [
                "$table.users_id",
            ],
            "FROM" => $table,
            "INNER JOIN" => [
                $itemTable => [
                    "ON" => [
                        $table => "items_id",
                        $itemTable => "id"
                    ]
                ]
            ],
            "WHERE" => [
                "$table.items_id" => $itemsId,
                "$table.itemtype" => $itemtype,
            ]
        ];
        $resultIterator = $DB->request($query);

        if ($resultIterator->count() === 0) return 0;

        $count = 0;

        // GET USER MODEL
        $model = new User();
        $countsByUserIds = [];
        foreach ($resultIterator as $res) {
            if (!isset($countsByUserIds[$res["users_id"]])) {
                $countsByUserIds[$res["users_id"]] = 0;
            }
            $countsByUserIds[$res["users_id"]]++;
        }
        $userIds = array_keys($countsByUserIds);
        // GET ALL USERS BY IDS
        $userArr = $model->find(["id" => $userIds]);
        foreach ($userArr as $arr) {
            // CHECK PERMISSIONS FOR EVERY PRESENT USER IN HISTORY LIST ONCE
            $obj = new User();
            $obj->getFromResultSet($arr);
            if (!$obj->canViewItem()) {
                // CURRENT USER CAN NOT VIEW HISTORY USER -> REMOVE FROM LIST
                unset($countsByUserIds[$obj->getID()]);
            }
        }
        unset($userArr, $obj);

        foreach ($countsByUserIds as $c) {
            $count += $c;
        }

        return $count;
    }

    /**
     * @see CommonGLPI::getTabNameForItem()
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array|string
    {
        if ($withtemplate || !$item instanceof CommonDBTM) return '';
        $nb = 0;
        if ($item instanceof User) {
            if (!Session::haveRight(self::$rightname, self::VIEW_ASSET_HISTORY) || !$item::canView() || !$item->canViewItem()) return '';
            if ($_SESSION['glpishow_count_on_tabs']) {
                $nb = self::countHistoryItemsForUser($item->getID());
            }
            return self::createTabEntry(__("Asset history", 'assetuserhistory'), $nb, null, "ti ti-replace");
        } else {
            if (!Session::haveRight(self::$rightname, self::VIEW_USER_HISTORY) || !$item::canView() || !$item->canViewItem()) return '';
            if ($_SESSION['glpishow_count_on_tabs']) {
                $nb = self::countHistoryItemsForAsset($item->getID(), $item::getType());
            }
            return self::createTabEntry(__("User history", 'assetuserhistory'), $nb);
        }
    }

    /**
     * @see CommonGLPI::displayTabContentForItem()
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof CommonDBTM) return true;
        if ($item instanceof User) {
            self::showForUser($item);
        } else {
            self::showForAsset($item);
        }

        return true;
    }

    /**
     * Displays the user history related to a given asset, including filtering, sorting, and pagination capabilities.
     *
     * @param CommonDBTM $item The asset item for which the user history will be displayed.
     * @return bool Returns true after successfully rendering the user history view.
     */
    public static function showForAsset(CommonDBTM $item): bool
    {
        if (!Session::haveRight(self::$rightname, self::VIEW_USER_HISTORY) || !$item::canView() || !$item->canViewItem()) {
            return true;
        }

        global $DB, $CFG_GLPI;

        $list_limit = $_SESSION['glpilist_limit'] ?? $CFG_GLPI['list_limit'];

        $sort = $_GET["sort"] ?? "";
        $order = strtoupper($_GET["order"]);
        $filters = $_GET['filters'] ?? [];
        $is_filtered = count($filters) > 0;
        $start = (int)($_GET["start"] ?? 0);
        if (empty($sort)) $sort = "assigned";
        if (empty($order)) $order = "DESC";

        $orderForQuery = $order === "ASC" ? "DESC" : "ASC";
        $orderBy = new QueryExpression($sort === "assigned"
            ? sprintf("isnull(%s) %s, -%s %s", $DB::quoteName($sort), $orderForQuery, $DB::quoteName($sort), $orderForQuery)
            : sprintf("-%s %s", $DB::quoteName($sort), $orderForQuery)
        );


        $table = self::getTable();
        $userTable = User::getTable();
        $query = [
            "SELECT" => [
                "$table.users_id",
                "$userTable.name as users_name",
                "$table.assigned",
                "$table.revoked",
            ],
            "FROM" => $table,
            "LEFT JOIN" => [
                $userTable => [
                    "ON" => [
                        $table => "users_id",
                        $userTable => "id"
                    ]
                ]
            ],
            "WHERE" => [
                "$table.items_id" => $item->getID(),
                "$table.itemtype" => $item::getType(),
            ],
            "ORDERBY" => $orderBy
        ];

        $results = iterator_to_array($DB->request($query));

        $userOptions = [];

        if (!empty($results)) {
            // GET USER MODEL
            $model = new User();
            // COLLECT USER IDS
            $indexesByUserIds = [];
            foreach ($results as $index => $res) {
                $uid = (int)$res["users_id"];
                if ($uid > 0) {
                    $indexesByUserIds[$uid][] = $index;
                }
            }
            $userIds = array_keys($indexesByUserIds);

            // GET ALL USERS BY IDS
            if (!empty($userIds)) {
                foreach ($model->find(["id" => $userIds]) as $arr) {
                    // CHECK PERMISSIONS FOR EVERY PRESENT USER IN HISTORY LIST ONCE
                    $user = new User();
                    $user->getFromResultSet($arr);
                    $userId = $user->getID();
                    $canView = $user->canViewItem();

                    // build user links
                    foreach ($indexesByUserIds[$userId] ?? [] as $index) {
                        if ($canView) {
                            $results[$index]["href"] = User::getFormURLWithID($user->getID());
                        } else {
                            unset($results[$index]);
                        }
                    }
                    if (!$canView) continue;
                    // build the user-options array
                    $userOptions[(string)$user->getID()] = $user->getField("name");
                    // add to navigate stack
                    Session::addToNavigateListItems(User::getType(), $user->getID());
                }
            }
        }

        $total_number = count($results);

        // FILTER
        if (!empty($filters["active"]) && $filters['active'] === '1') {
            $userFilter = isset($filters['user']) && is_array($filters["user"])
                ? array_values(array_filter(array_map(static fn($num) => (int)$num, $filters["user"])))
                : [];
            if (!empty($userFilter)) {
                $results = array_values(array_filter(
                    $results,
                    static fn($r) => in_array((int)$r["users_id"], $userFilter, true)
                ));
            }
        }

        $results = array_values($results);
        $filtered_number = count($results);
        $results = array_splice($results, $start, $list_limit);

        foreach ($results as &$res) {
            if (isset($res["href"])) {
                $res["user"] = '<a href="' . $res["href"] . '">' . $res["users_name"] . '</a>';
            } else if (empty($res["users_name"])) {
                $res["user"] = '<i>' . __('User deleted', 'assetuserhistory') . '</i>';
            } else {
                $res["user"] = $res["users_name"];
            }
            $res["assigned"] = $res["assigned"] ? Html::convDateTime($res["assigned"], null, true) : "?";
            $res["revoked"] = Html::convDateTime($res["revoked"], null, true);
            unset($res["users_name"], $res["href"]);
        }
        unset($res);

        TemplateRenderer::getInstance()->display('components/datatable.html.twig', [
            'start' => $start,
            'sort' => $sort,
            'order' => $order,
            'limit' => $list_limit,
            'additional_params' => $is_filtered ? http_build_query([
                'filters' => $filters,
            ]) : "",
            'is_tab' => true,
            'use_pager' => true,
            'items_id' => $item->getID(),
            'filters' => $filters,
            'columns' => [
                'user' => [
                    'label' => __("Login"),
                    'filter_formatter' => 'array',
                ],
                'assigned' => [
                    'label' => __("Assigned", "assetuserhistory"),
                    'no_filter' => true,
                ],
                'revoked' => [
                    'label' => __("Revoked", "assetuserhistory"),
                    'no_filter' => true,
                ],
            ],
            'columns_values' => [
                'user' => $userOptions,
            ],
            'formatters' => [
                'user' => 'raw_html',
                'assigned' => 'raw_html',
                'revoked' => 'raw_html',
            ],
            'entries' => $results,
            'total_number' => $total_number,
            'filtered_number' => $filtered_number,
        ]);

        return true;
    }

    /**
     * Displays the user-specific asset information with filtering, sorting, and pagination.
     *
     * This method retrieves, processes, and outputs the asset-related data tied to the provided user.
     * It handles sorting, filtering, and permission checks on asset data to ensure
     * only accessible items are included in the returned dataset.
     *
     * @param User $user The user object for whom the asset data is processed.
     * @return bool Returns true after successfully rendering the data.
     */
    public static function showForUser(User $user): bool
    {
        if (!Session::haveRight(self::$rightname, self::VIEW_ASSET_HISTORY) || !$user::canView() || !$user->canViewItem()) {
            return true;
        }

        global $DB, $CFG_GLPI;

        $list_limit = $_SESSION['glpilist_limit'] ?? $CFG_GLPI['list_limit'];

        $sort = $_GET["sort"] ?? "";
        $order = strtoupper($_GET["order"]);
        $filters = $_GET['filters'] ?? [];
        $is_filtered = count($filters) > 0;
        $start = (int)($_GET["start"] ?? 0);
        if (empty($sort)) $sort = "assigned";
        if (empty($order)) $order = "DESC";

        $orderForQuery = $order === "ASC" ? "DESC" : "ASC";
        $orderBy = new QueryExpression($sort === "assigned"
            ? sprintf("isnull(%s) %s, -%s %s", $DB::quoteName($sort), $orderForQuery, $DB::quoteName($sort), $orderForQuery)
            : sprintf("-%s %s", $DB::quoteName($sort), $orderForQuery)
        );

        $table = self::getTable();
        $query = [
            "SELECT" => [
                "$table.items_id",
                "$table.itemtype",
                "$table.assigned",
                "$table.revoked",
            ],
            "FROM" => $table,
            "WHERE" => [
                "$table.users_id" => $user->getID(),
            ],
            "ORDERBY" => $orderBy
        ];

        $results = iterator_to_array($DB->request($query));
        $itemtypeOptions = [];

        if (!empty($results)) {
            // CREATE HASHMAP FOR ASSETS BY TYPE TO ACCESS ASSET INDEXES AND IDS FASTER
            $indexesByItemtypeAndId = [];
            foreach ($results as $index => $res) {
                $indexesByItemtypeAndId[$res["itemtype"]][$res["items_id"]][] = $index;
            }

            // CHECK PERMISSIONS AND GET ASSET INFORMATION
            foreach ($indexesByItemtypeAndId as $itemtype => $indexesByIds) {
                $model = getItemForItemtype($itemtype);
                $allIndexes = array_merge([], ...$indexesByIds);
                if (!$model || !$model::canView()) {
                    foreach ($allIndexes as $index) {
                        unset($results[$index]);
                    }
                    continue;
                }

                // EXTRACT ITEM IDS FOR CURRENT TYPE
                $itemIds = array_keys($indexesByIds);
                $obj = new $model;

                $itemtypeOptions[$itemtype] = $model::getTypeName(1);

                // GET EXISTING ASSETS BY IDS
                $items = array_map(static function ($i) use ($model) {
                    $o = new $model;
                    $o->getFromResultSet($i);
                    return $o;
                }, $obj->find(['id' => $itemIds]));

                foreach ($items as $i) {
                    if (!$i->canViewItem()) {
                        foreach ($indexesByIds[$i->getID()] as $index) {
                            unset($results[$index]);
                        }
                    } else {
                        foreach ($indexesByIds[$i->getID()] as $index) {
                            $results[$index]["items_name"] = $i->getName();
                        }
                    }
                }
            }
        }

        $total_number = count($results);

        // FILTER
        if (!empty($filters["active"]) && $filters['active'] === '1') {
            $itemtypeFilter = !empty($filters['itemtype']) && is_array($filters["itemtype"]) ? $filters["itemtype"] : [];
            $itemNameFilter = strtoupper(trim($filters['items_name'] ?? ""));
            if (!empty($itemtypeFilter) || !empty($itemNameFilter)) {
                $results = array_filter(
                    $results,
                    static fn($r) => (count($itemtypeFilter) === 0 || in_array($r["itemtype"], $itemtypeFilter, true))
                        && ($itemNameFilter === "" || str_contains(strtoupper($r["items_name"]), $itemNameFilter))
                );
            }
        }

        foreach ($results as &$res) {
            $model = getItemForItemtype($res["itemtype"]);
            $res["itemtype"] = $model::getTypeName(1);
            $res["items_name"] = '<a href="' . $model::getFormURLWithID($res["items_id"]) . '">' . $res["items_name"] . '</a>';
            $res["assigned"] = $res["assigned"] ? Html::convDateTime($res["assigned"], null, true) : "?";
            $res["revoked"] = Html::convDateTime($res["revoked"], null, true);
            Session::addToNavigateListItems($model::getType(), $res["items_id"]);
        }
        unset($res);

        $results = array_values($results);
        $filtered_number = count($results);
        $results = array_splice($results, $start, $list_limit);

        TemplateRenderer::getInstance()->display('components/datatable.html.twig', [
            'start' => $start,
            'sort' => $sort,
            'order' => $order,
            'limit' => $list_limit,
            'additional_params' => $is_filtered ? http_build_query([
                'filters' => $filters,
            ]) : "",
            'is_tab' => true,
            'use_pager' => true,
            'items_id' => $user->getID(),
            'filters' => $filters,
            'columns' => [
                'items_name' => [
                    'label' => __("Name"),
                ],
                'itemtype' => [
                    'label' => __("Type"),
                    'filter_formatter' => 'array',
                ],
                'assigned' => [
                    'label' => __("Assigned", "assetuserhistory"),
                    'no_filter' => true,
                ],
                'revoked' => [
                    'label' => __("Revoked", "assetuserhistory"),
                    'no_filter' => true,
                ],
            ],
            'columns_values' => [
                'itemtype' => $itemtypeOptions,
            ],
            'formatters' => [
                'items_name' => 'raw_html',
                'itemtype' => 'raw_html',
                'assigned' => 'raw_html',
                'revoked' => 'raw_html',
            ],
            'entries' => $results,
            'total_number' => $total_number,
            'filtered_number' => $filtered_number,
        ]);

        return true;
    }

}