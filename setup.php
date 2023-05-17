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

use Glpi\Plugin\Hooks;

const PLUGIN_ASSETUSERHISTORY_VERSION = '1.0.0';

// Minimal GLPI version, inclusive
const PLUGIN_ASSETUSERHISTORY_MIN_GLPI_VERSION = "10.0";
// Maximum GLPI version, exclusive
const PLUGIN_ASSETUSERHISTORY_MAX_GLPI_VERSION = "11.0";

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

    $PLUGIN_HOOKS['csrf_compliant']['assetuserhistory'] = true;
    $plugin = new Plugin();

    if (Session::getLoginUserID() && $plugin->isActivated('assetuserhistory')) {
        $plugin::registerClass('PluginAssetuserhistoryAssetHistory', [
            'addtabon' => ['User', 'Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'],
        ]);

        // FIRE ON DELETE FUNCTION (PERMANENTLY) TO DELETE HISTORY WHEN ASSET OF FOLLOWING TYPES IS DELETED
        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['assetuserhistory'] = [
            'Computer' => 'plugin_assetuserhistory_item_purge_asset',
            'Monitor' => 'plugin_assetuserhistory_item_purge_asset',
            'NetworkEquipment' => 'plugin_assetuserhistory_item_purge_asset',
            'Peripheral' => 'plugin_assetuserhistory_item_purge_asset',
            'Phone' => 'plugin_assetuserhistory_item_purge_asset',
            'Printer' => 'plugin_assetuserhistory_item_purge_asset',
            'User' => 'plugin_assetuserhistory_item_purge_user'
        ];
    }
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
                'min' => PLUGIN_ASSETUSERHISTORY_MIN_GLPI_VERSION,
                'max' => PLUGIN_ASSETUSERHISTORY_MAX_GLPI_VERSION,
            ]
        ]
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONNAL, but recommanded
 *
 * @return boolean
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_ASSETUSERHISTORY_MIN_GLPI_VERSION, 'lt') || version_compare(GLPI_VERSION, PLUGIN_ASSETUSERHISTORY_MAX_GLPI_VERSION, 'ge')) {
        echo "This plugin requires GLPI >= " . PLUGIN_ASSETUSERHISTORY_MIN_GLPI_VERSION . " and GLPI < " . PLUGIN_ASSETUSERHISTORY_MAX_GLPI_VERSION;
        return false;
    }
    return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_check_config($verbose = false): bool
{
    if ($verbose) {
        echo 'Installed / not configured';
    }
    return true;
}
