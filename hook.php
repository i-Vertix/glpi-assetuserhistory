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

use GlpiPlugin\Assetuserhistory\History;

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

    $injections = plugin_assetuserhistory_injections();
    History::install($migration, $injections);

    $migration->executeMigration();

    $isEmpty = (int)($DB->request([
            "FROM" => History::getTable(),
            "COUNT" => "cnt",
        ])->current()["cnt"] ?? 0) === 0;

    foreach ($injections as $injection) {
        // skip user injection
        if (!method_exists($injection, 'getType')) continue;
        $injectionType = $injection::getType();
        if ($injectionType === User::getType()) continue;

        History::installTrigger($injectionType);
        // import current assigned user if table is freshly installed
        if ($isEmpty) History::importCurrent($injectionType);
    }

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
    $migration = new Migration(PLUGIN_ASSETUSERHISTORY_VERSION);

    History::uninstallTriggers();
    History::uninstall($migration);

    $migration->executeMigration();

    return true;
}

/**
 * @param CommonDBTM $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_item_purge_asset(CommonDBTM $item): void
{
    // delete history when asset gets deleted
    $history = new History();
    $history->deleteByCriteria([
        "itemtype" => $item::getType(),
        "items_id" => $item->getID(),
    ], true);
}

/**
 * @param User $item
 * @return void
 * @noinspection PhpUnused
 */
function plugin_assetuserhistory_item_purge_user(User $item): void
{
    global $DB;

    // keep users with id 0 instead of deleting them
    $DB->update(
        History::getTable(),
        [
            "users_id" => 0,
        ],
        [
            "WHERE" => [
                "users_id" => $item->getID(),
            ]
        ]
    );
}
