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
            `id` int unsigned not null primary key auto_increment,
            `users_id` int unsigned not null ,
            `assets_id` int unsigned not null ,
            `assets_type` varchar(255) not null ,
            `assigned` timestamp null,
            `revoked` timestamp null
          )";
        $DB->queryOrDie($query, "table glpi_plugin_assetuserhistory_history created");
    }

    // WITHOUT STRICT_TRANS_TABLES TRIGGERS DO NOT WORK CORRECTLY
    $DB->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");

    $tablesForTriggers = [
        ["glpi_computers", "Computer"],
        ["glpi_monitors", "Monitor"],
        ["glpi_networkequipments", "NetworkEquipment"],
        ["glpi_peripherals", "Peripheral"],
        ["glpi_phones", "Phone"],
        ["glpi_printers", "Printer"],
    ];

    foreach ($tablesForTriggers as [$table, $type]) {
        $name = strtolower($type);

        $DB->queryOrDie("
create or replace trigger plugin_assetuserhistory_". $name. "_add
    after insert
    on " . $table . "
    for each row
begin
    DECLARE aType varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
    SET aType = '" . $type . "';
    if (NEW.users_id <> 0 and NEW.users_id is not null and NEW.users_id is not null) then
        insert into glpi_plugin_assetuserhistory_history (users_id, assets_id, assets_type, assigned)
        values (NEW.users_id, NEW.id, aType, NOW());
    end if;
end;
");

        $DB->queryOrDie("
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

    $triggerNames = [
        "computer",
        "monitor",
        "networkequipment",
        "peripheral",
        "phone",
        "printer",
    ];

    foreach ($triggerNames as $name) {
        $DB->queryOrDie("drop trigger if exists plugin_assetuserhistory_" . $name . "_add;");
        $DB->queryOrDie("drop trigger if exists plugin_assetuserhistory_" . $name . "_update;");
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
