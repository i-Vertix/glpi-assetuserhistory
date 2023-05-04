<?php

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

/**
 * Plugin install process
 *
 * @return boolean
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_install(): bool
{
    global $DB;
    $migration = new Migration(PLUGIN_ASSETUSERHISTORY_VERSION);

    // COMPUTER
    if (!$DB->tableExists("glpi_plugin_assetuserhistory_history")) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_assetuserhistory_history` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id` int(11) UNSIGNED NOT NULL,
            `assets_id` int(11) UNSIGNED NOT NULL,
            `assets_type` varchar(255) NOT NULL,
            `assigned` timestamp NULL,
            `revoked` timestamp NULL,
            PRIMARY KEY (`id`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        $DB->queryOrDie($query, "table glpi_plugin_assetuserhistory_history created");
    }

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_uninstall(): bool
{
    global $DB;

    if ($DB->tableExists("glpi_plugin_assetuserhistory_history")) {
        $query = "DROP TABLE glpi_plugin_assetuserhistory_history;";
        $DB->queryOrDie($query, "table glpi_plugin_assetuserhistory_history deleted");
    }

    return true;
}

/**
 * @param CommonDBTM $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_pre_item_update_asset(CommonDBTM $item): void
{
    // WHEN ASSET HAS NO FIELD "USERS_ID" -> RETURN
    if (!isset($item->fields['users_id'], $item->fields['entities_id'])) return;
    // IF USER ID NOT SET, INPUT VALUE IS 0
    $usersId = (int)$item->input['users_id'];
    $assetsId = $item->fields['id'];
    $assetsType = $item::getType();

    global $DB;
    $oldUsersId = $item->fields['users_id'];

    // NO CHANGE - RETURN
    if ($oldUsersId === $usersId) return;

    // UPDATE OLD HISTORY ENTRY (REVOKED UNSET) IF EXISTS
    if ($oldUsersId) {
        $stmt = $DB->prepare("update glpi_plugin_assetuserhistory_history set 
                    revoked = NOW() where users_id = ? and assets_id = ? 
                                                    and assets_type = ? and revoked is null");
        $stmt->bind_param("iis", $oldUsersId, $assetsId, $assetsType);
        $stmt->execute();
    }

    // ADD NEW HISTORY ENTRY FOR NEWLY ASSIGNED USER ID
    if ($usersId) {
        $stmt = $DB->prepare("insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type, assigned)
                                        values (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $usersId, $assetsId, $assetsType);
        $stmt->execute();
    }
}

/**
 * @param CommonDBTM $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_item_add_asset(CommonDBTM $item): void
{
    // WHEN ASSET HAS NO FIELD "USERS_ID" -> RETURN
    if (!isset($item->fields['users_id'])) return;
    $usersId = (int)$item->input['users_id'];
    // IF USER ID NOT SET, INPUT VALUE IS 0
    // NO USER ASSIGNED -> NO HISTORY TO WRITE -> RETURN
    if ($usersId === 0) return;

    $assetsId = $item->fields['id'];
    $assetsType = $item::getType();

    global $DB;

    // ADD NEW HISTORY ENTRY FOR ASSIGNED USER ID
    $stmt = $DB->prepare("insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type, assigned)
                                        values (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $usersId, $assetsId, $assetsType);
    $stmt->execute();
}

/**
 * @param CommonDBTM $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_item_purge_asset(CommonDBTM $item): void
{
    global $DB;

    $stmt = $DB->prepare("delete from glpi_plugin_assetuserhistory_history where assets_type = ? and assets_id = ?");
    $type = $item::getType();
    $id = $item->getID();
    $stmt->bind_param("si", $type, $id);
    $stmt->execute();
}

/**
 * @param User $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_item_purge_user(User $item): void
{
    global $DB;

    $stmt = $DB->prepare("delete from glpi_plugin_assetuserhistory_history where users_id = ?");
    $id = $item->getID();
    $stmt->bind_param("i", $id);
    $stmt->execute();
}
