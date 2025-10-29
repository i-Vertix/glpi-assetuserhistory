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

namespace GlpiPlugin\Assetuserhistory;

use CommonDBTM;
use CommonGLPI;
use Config as Glpi_Config;
use DBConnection;
use DBmysql;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Asset\Asset;
use Session;
use User;
use function Safe\json_encode;
use function Safe\json_decode;

class Config extends Glpi_Config
{

    private const CONFIG_CONTEXT = "plugin:assetuserhistory";
    private const DEFAULT_INJECTIONS = ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'];


    public static function getTypeName($nb = 0)
    {
        return __s('Asset-User history', 'assetuserhistory');
    }

    /**
     * @param string|null $itemtype
     * @return string[]
     */
    public static function getExpectedTriggerNamesForItemtype(?string $itemtype): array
    {
        $base = 'plugin_assetuserhistory_';
        if ($itemtype === null) {
            $name = "%";
        } else {
            $name = preg_replace('/[^a-z]/', '_', strtolower($itemtype));
        }
        return [
            "add" => $base . $name . '_add',
            "update" => $base . $name . '_update'
        ];
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

        $table = History::getTable();
        $itemTable = getTableForItemType($itemtype);
        if (empty($itemTable) || !$DB->tableExists($itemTable)) return false;

        // clean trigger name
        $triggers = self::getExpectedTriggerNamesForItemtype($itemtype);

        if ($itemTable !== Asset::getTable()) {
            // normal or plugin asset
            $query = <<<SQL
                create trigger {$triggers["add"]}
                    after insert
                    on `{$itemTable}`
                    for each row
                begin
                    DECLARE aType varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                    SET aType = '{$itemtype}';
                    if (NEW.users_id <> 0 and NEW.users_id is not null) then
                        insert into {$table} (users_id, items_id, itemtype, assigned)
                        values (NEW.users_id, NEW.id, aType, NOW());
                    end if;
                end;
            SQL;
            $DB->doQuery($query);

            $query = <<<SQL
                create trigger {$triggers["update"]}
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
                        and itemtype = aType
                        and revoked is null;
                
                        if (NEW.users_id <> 0) then
                            insert into {$table} (users_id, items_id, itemtype, assigned)
                            values (NEW.users_id, NEW.id, aType, NOW());
                        end if;
                    end if;
                end;
            SQL;
            $DB->doQuery($query);
        } else {
            // custom asset
            $eItemtype = $DB::quoteValue($itemtype);
            $query = <<<SQL
                create trigger {$triggers["add"]}
                    after insert
                    on `{$itemTable}`
                    for each row
                begin
                    DECLARE aType varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                    DECLARE dSystemName varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                    SET aType = {$eItemtype};
                    select `system_name` into dSystemName from glpi_assets_assetdefinitions where id = NEW.assets_assetdefinitions_id limit 1;
                    if (dSystemName is not null and NEW.users_id <> 0 and NEW.users_id is not null and aType = concat('Glpi\\\\CustomAsset\\\\', dSystemName, 'Asset')) then
                        insert into {$table} (users_id, items_id, itemtype, assigned)
                        values (NEW.users_id, NEW.id, aType, NOW());
                    end if;
                end;
            SQL;
            $DB->doQuery($query);

            $query = <<<SQL
                create trigger {$triggers["update"]}
                    after update
                    on `{$itemTable}`
                    for each row
                begin
                    DECLARE aType varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                    DECLARE dSystemName varchar(255) CHARACTER SET {$default_charset} COLLATE {$default_collation};
                    SET aType = {$eItemtype};
                    select `system_name` into dSystemName from glpi_assets_assetdefinitions where id = `OLD`.assets_assetdefinitions_id limit 1;
                    if (dSystemName is not null and aType = concat('Glpi\\\\CustomAsset\\\\', dSystemName, 'Asset') and NEW.users_id <> OLD.users_id) then
                    
                        update {$table}
                        set revoked = NOW()
                        where users_id = OLD.users_id
                        and items_id = NEW.id
                        and itemtype = aType
                        and revoked is null;
                
                        if (NEW.users_id <> 0) then
                            insert into {$table} (users_id, items_id, itemtype, assigned)
                            values (NEW.users_id, NEW.id, aType, NOW());
                        end if;
                    end if;
                end;
            SQL;
            $DB->doQuery($query);
        }

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

        $triggerNames = self::getExpectedTriggerNamesForItemtype(null);

        $query = [
            "SELECT" => ["TRIGGER_NAME"],
            "FROM" => "information_schema.TRIGGERS",
            "WHERE" => [
                "OR" => [
                    [
                        "TRIGGER_NAME" => ["LIKE", $triggerNames["add"]]
                    ],
                    [
                        "TRIGGER_NAME" => ["LIKE", $triggerNames["update"]]
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
     * @return array|null
     */
    private static function getLegacyCustomInjections(): ?array
    {
        if (!defined('GLPI_PLUGIN_DOC_DIR')) {
            return null;
        }

        $file = GLPI_PLUGIN_DOC_DIR . '/assetuserhistory/injections.list';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $content = trim((string)file_get_contents($file));
        if ($content === '') {
            return null;
        }

        $injections = array_filter(
            array_unique(preg_split('/[\s,]+/', $content, -1, PREG_SPLIT_NO_EMPTY)),
            static fn($i) => $i !== 'User'
        );
        if (empty($injections)) {
            return null;
        }

        // Validate all classes exist
        foreach ($injections as $class) {
            if (!class_exists($class) || !(new $class) instanceof CommonDBTM) {
                return null;
            }
        }

        return array_values($injections);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public static function install(): void
    {
        // clear prev triggers
        self::uninstallTriggers();
        // check if injection already set - set default if empty
        $injectionItemtypes = self::getInjectionItemtypes();
        if (empty($injectionItemtypes)) {
            // get legacy custom injections or use default
            $defaultInjections = self::getLegacyCustomInjections() ?? self::DEFAULT_INJECTIONS;
            Glpi_Config::setConfigurationValues(self::CONFIG_CONTEXT, [
                "inject_item_type" => json_encode($defaultInjections)
            ]);
        }
        // register itemtypes
        $injectionItemtypes = self::getInjectionItemtypes();
        foreach ($injectionItemtypes as $itemtype) {
            if (self::installTrigger($itemtype)) {
                Session::addMessageAfterRedirect("✅ Enabled user-history for " . $itemtype);
            }
        }
    }

    /**
     * @return void
     */
    public static function uninstall(): void
    {
        $config = new Glpi_Config();
        $config->deleteByCriteria(["context" => self::CONFIG_CONTEXT]);
        self::uninstallTriggers();
    }

    /**
     * @inheritDoc
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $nb = 0;
        switch ($item->getType()) {
            case Glpi_Config::class:
                if ($_SESSION['glpishow_count_on_tabs']) {
                    $nb = count(self::getInjectionItemtypes());
                }
                return self::createTabEntry(
                    self::getTypeName(),
                    $nb,
                    $item::getType(),
                    'ti ti-replace-user'
                );
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public static function displayTabContentForItem(
        CommonGLPI $item,
                   $tabnum = 1,
                   $withtemplate = 0
    ): bool
    {
        if ($item instanceof Glpi_Config) {
            return self::showForConfig($item, $withtemplate);
        }

        return true;
    }

    public static function dropdownInjectableItemtypes($options)
    {
        global $CFG_GLPI;
        $p['name'] = 'inject_item_type';
        $p['values'] = [];
        $p['display'] = true;

        $values = [];
        foreach ($CFG_GLPI["assignable_types"] as $itemtype) {
            if (($item = getItemForItemtype($itemtype)) && User::getType() !== $itemtype) {
                $values[$itemtype] = $item::getTypeName();
            }
        }
        if (is_array($options) && !empty($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        $p['multiple'] = true;
        $p['size'] = 3;
        return Dropdown::showFromArray($p['name'], $values, $p);
    }

    /**
     * @return array
     */
    public static function getInjectionItemtypes(): array
    {
        $current_config = Glpi_Config::getConfigurationValues(self::CONFIG_CONTEXT);
        try {
            return json_decode($current_config["inject_item_type"] ?? "[]");
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * @param Glpi_Config $config
     * @param int $withtemplate
     * @return bool
     */
    public static function showForConfig(Glpi_Config $config, int $withtemplate = 0): bool
    {
        global $DB;

        if (!self::canUpdate()) {
            return false;
        }

        $current_inject_item_type = self::getInjectionItemtypes();
        $canedit = Session::haveRight(self::$rightname, UPDATE);

        $triggerNames = self::getExpectedTriggerNamesForItemtype(null);

        $query = [
            "COUNT" => "cnt",
            "FROM" => "information_schema.TRIGGERS",
            "WHERE" => [
                "OR" => [
                    [
                        "TRIGGER_NAME" => ["LIKE", $triggerNames["add"]]
                    ],
                    [
                        "TRIGGER_NAME" => ["LIKE", $triggerNames["update"]]
                    ],
                ],
                "TRIGGER_SCHEMA" => $DB->dbdefault
            ]
        ];
        $triggerCount = $DB->request($query)->current()["cnt"] ?? 0;

        TemplateRenderer::getInstance()->display('@assetuserhistory/config.html.twig', [
            'item' => $config,
            'current_inject_item_type' => $current_inject_item_type,
            'canedit' => $canedit,
            "config_context" => self::CONFIG_CONTEXT,
        ]);

        if ($canedit && count($current_inject_item_type) * 2 !== $triggerCount) {
            TemplateRenderer::getInstance()->display('@assetuserhistory/repairTriggers.html.twig');
        }

        return true;
    }

    /**
     * Prepares and formats the configuration update for the provided item by validating
     * and encoding input data.
     *
     * @param CommonDBTM $item The item object containing configuration input data to be prepared.
     * @return void
     * @throws \Exception
     */
    public static function prepareConfigUpdate(CommonDBTM $item): void
    {
        global $CFG_GLPI;
        if (!is_array($item->input["inject_item_type"] ?? null)) {
            $item->input["inject_item_type"] = [];
        }
        if (empty($item->input['inject_item_type'])) {
            $item->input["inject_item_type"] = self::DEFAULT_INJECTIONS;
        }
        foreach ($item->input["inject_item_type"] as $key => $value) {
            if (!in_array($value, $CFG_GLPI["assignable_types"], true)) {
                unset($item->input["inject_item_type"][$key]);
            }
        }
        $item->input["inject_item_type"] = json_encode(array_values($item->input["inject_item_type"]));
    }

    /**
     * @return int
     */
    public static function registerAllItemtypes(): int
    {
        global $DB;
        self::uninstallTriggers();
        $injectionItemtypes = self::getInjectionItemtypes();
        foreach ($injectionItemtypes as $itemtype) {
            $ok = self::installTrigger($itemtype);
            $isEmpty = (int)($DB->request([
                    "COUNT" => "cnt",
                    "FROM" => History::getTable(),
                    "WHERE" => [
                        "itemtype" => $itemtype,
                    ]
                ])->current()["cnt"] ?? 0) === 0;
            // import if no history for itemtype
            if ($ok && $isEmpty) History::importCurrent($itemtype);
        }
        return count($injectionItemtypes);
    }

}