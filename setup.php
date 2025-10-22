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

use Glpi\Plugin\Hooks;

const PLUGIN_ASSETUSERHISTORY_VERSION = '1.2.0';

/**
 * Retrieve the list of allowed asset user history injections
 *
 * @return array List of asset types for which user history injections are applicable
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_injections(): array
{
    $default = ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'];
    if (!defined('GLPI_PLUGIN_DOC_DIR')) {
        return $default;
    }

    $file = GLPI_PLUGIN_DOC_DIR . '/assetuserhistory/injections.list';
    if (!is_file($file) || !is_readable($file)) {
        return $default;
    }

    $content = trim((string)file_get_contents($file));
    if ($content === '') {
        return $default;
    }

    $injections = array_unique(preg_split('/[\s,]+/', $content, -1, PREG_SPLIT_NO_EMPTY));
    if (empty($injections)) {
        return $default;
    }

    // Always ensure "User" is present and remove duplicates
    $injections = array_filter($injections, static fn($i) => $i !== 'User');

    // Validate all classes exist
    foreach ($injections as $class) {
        if (!class_exists($class) || !(new $class) instanceof CommonDBTM) {
            return $default;
        }
    }

    return array_values($injections);
}

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 * @noinspection PhpUnused
 */
function plugin_init_assetuserhistory(): void
{
    global $PLUGIN_HOOKS;

    // UNCOMMENT THE FOLLOWING BLOCK IF TRIGGERS FAIL TO INSTALL - STRICT_TRANS_TABLES NEED TO BE ENABLED FOR SESSION FOR TRIGGERS TO INSTALL CORRECTLY
    /*
    global $DB;
    // ADDS STRICT_TRANS_TABLES TO SQL MODE IF NOT ALREADY PRESENT
    $DB->doQuery("SET SESSION sql_mode = IF(LOCATE('STRICT_TRANS_TABLES', @@SESSION.sql_mode) = 0, CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES'), @@SESSION.sql_mode)");
    // ADDS NO_ENGINE_SUBSTITUTION TO SQL MODE IF NOT ALREADY PRESENT
    $DB->doQuery("SET SESSION sql_mode = IF(LOCATE('NO_ENGINE_SUBSTITUTION', @@SESSION.sql_mode) = 0, CONCAT(@@SESSION.sql_mode, ',NO_ENGINE_SUBSTITUTION'), @@SESSION.sql_mode)");
    */

    $plugin = new Plugin();

    $injections = plugin_assetuserhistory_injections();
    if (Session::getLoginUserID() && $plugin->isActivated('assetuserhistory')) {
        if (Session::haveRight(GlpiPlugin\Assetuserhistory\History::$rightname, GlpiPlugin\Assetuserhistory\History::VIEW_USER_HISTORY)) {
            $plugin::registerClass(GlpiPlugin\Assetuserhistory\History::class, [
                'addtabon' => $injections,
            ]);
        }
        if (Session::haveRight(GlpiPlugin\Assetuserhistory\History::$rightname, GlpiPlugin\Assetuserhistory\History::VIEW_ASSET_HISTORY)) {
            $plugin::registerClass(GlpiPlugin\Assetuserhistory\History::class, [
                'addtabon' => [User::class],
            ]);
        }
    }

    // PROFILE (ACL)
    Plugin::registerClass(GlpiPlugin\Assetuserhistory\Profile::class, ['addtabon' => [Profile::class]]);

    // FIRE ON DELETE FUNCTION (PERMANENTLY) TO DELETE HISTORY WHEN ITEM OF INJECTED TYPES IS DELETED
    $purgeActions = [
        User::class => "plugin_assetuserhistory_item_purge_user"
    ];
    foreach ($injections as $injection) {
        $purgeActions[$injection] = "plugin_assetuserhistory_item_purge_asset";
    }
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['assetuserhistory'] = $purgeActions;
}


/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 * @noinspection PhpUnused
 */
function plugin_version_assetuserhistory(): array
{
    return [
        'name' => 'Asset-User History',
        'shortname' => 'assetuserhistory',
        'version' => PLUGIN_ASSETUSERHISTORY_VERSION,
        'author' => 'i-Vertix',
        'license' => 'MIT',
        'homepage' => 'i-vertix.com',
        'requirements' => [
            'glpi' => [
                'min' => "11.0.0",
                'max' => "11.0.99",
            ]
        ]
    ];
}
