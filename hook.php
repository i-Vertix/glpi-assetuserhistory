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
 * Creates add and update triggers for the given table
 * @param string $table
 * @param string $type
 * @return void
 */
function plugin_assetuserhistory_create_triggers(string $table, string $type): void
{
    global $DB;

    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    $name = strtolower($type);

    $DB->doQueryOrDie("
create or replace trigger plugin_assetuserhistory_" . $name . "_add
    after insert
    on " . $table . "
    for each row
begin
    DECLARE aType varchar(50) CHARACTER SET {$default_charset} COLLATE {$default_collation};
    SET aType = '" . $type . "';
    if (NEW.users_id <> 0 and NEW.users_id is not null and NEW.users_id is not null) then
        insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type, assigned)
        values (NEW.users_id, NEW.id, aType, NOW());
    end if;
end;
");

    $DB->doQueryOrDie("
create or replace trigger plugin_assetuserhistory_" . $name . "_update
    after update
    on " . $table . "
    for each row
begin
    DECLARE assets_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
    SET assets_type = '" . $type . "';
    if (NEW.users_id <> OLD.users_id) then
    
        update glpi_plugin_assetuserhistory_history
        set revoked = NOW()
        where users_id = OLD.users_id
        and assets_id = NEW.id
        and assets_type = assets_type
        and revoked is null;

        if (NEW.users_id <> 0) then
            insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type, assigned)
            values (NEW.users_id, NEW.id, assets_type, NOW());
        end if;
    end if;
end;
");
}


/**
 * @return int 0|1 = 0 not installed, 1 = installed
 */
function plugin_assetuserhistory_needUpdateOrInstall() {
    /** @var DBmysql $DB */
    global $DB;

    // check if table exists
    if (!$DB->tableExists('glpi_plugin_assetuserhistory_history')) {
        return 0;
    }

    return 1;
}

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

    $default_charset = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

    // create the history table
    if (!$DB->tableExists("glpi_plugin_assetuserhistory_history")) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_assetuserhistory_history` (
            `id` int {$default_key_sign} not null primary key auto_increment,
            `users_id` int {$default_key_sign} not null ,
            `assets_id` int {$default_key_sign} not null ,
            `assets_type` varchar(255) not null ,
            `assigned` timestamp null,
            `revoked` timestamp null
          ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQueryOrDie($query, "table glpi_plugin_assetuserhistory_history created");
    }

    // WITHOUT STRICT_TRANS_TABLES TRIGGERS DO NOT WORK CORRECTLY
    $DB->doQuery("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");

    $plugin = new Plugin();

    $tablesForTriggers = [
        ["glpi_computers", "Computer"],
        ["glpi_monitors", "Monitor"],
        ["glpi_networkequipments", "NetworkEquipment"],
        ["glpi_peripherals", "Peripheral"],
        ["glpi_phones", "Phone"],
        ["glpi_printers", "Printer"],
    ];

    if ($plugin->isInstalled("simcard")) {
        $tablesForTriggers[] = ["glpi_plugin_simcard_simcards", "PluginSimcardSimcard"];
    }

    foreach ($tablesForTriggers as [$table, $type]) {
        plugin_assetuserhistory_create_triggers($table, $type);

        $DB->doQuery("insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type) 
select t.users_id, t.id, '" . $type . "' from " . $table . " as t
left join glpi_plugin_assetuserhistory_history as h on h.assets_id = t.id and h.assets_type = '" . $type . "'
where t.users_id <> 0 and t.users_id is not null and t.is_deleted = 0 and h.id is null;");
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
        $DB->doQueryOrDie($query, "table glpi_plugin_assetuserhistory_history deleted");
    }

    $plugin = new Plugin();

    $triggerNames = [
        "computer",
        "monitor",
        "networkequipment",
        "peripheral",
        "phone",
        "printer",
    ];

    if ($plugin->isInstalled("simcard")) {
        $triggerNames[] = "pluginsimcardsimcard";
    }

    foreach ($triggerNames as $name) {
        $DB->doQueryOrDie("drop trigger if exists plugin_assetuserhistory_" . $name . "_add;");
        $DB->doQueryOrDie("drop trigger if exists plugin_assetuserhistory_" . $name . "_update;");
    }


    return true;
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

/**
 * @param string $plugin
 * @return void
 */
function plugin_assetuserhistory_ext_plugin_install(string $plugin): void
{
    if ($plugin === "simcard") {
        plugin_assetuserhistory_create_triggers("glpi_plugin_simcard_simcards", "PluginSimcardSimcard");
    }
}

/**
 * @param string $plugin
 * @return void
 */
function plugin_assetuserhistory_ext_plugin_uninstall(string $plugin): void
{
    global $DB;
    if ($plugin === "simcard") {
        $DB->doQuery("delete from glpi_plugin_assetuserhistory_history where assets_type = 'PluginSimcardSimcard'");
    }
}
