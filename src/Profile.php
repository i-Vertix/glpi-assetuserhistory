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
use Glpi\Application\View\TemplateRenderer;
use Profile as GlpiProfile;

class Profile extends GlpiProfile
{

    /**
     * @return string
     */
    public static function getIcon(): string
    {
        return History::getIcon();
    }

    /**
     * @param CommonGLPI $item
     * @param int $withtemplate
     * @return array|string
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array|string
    {
        return self::createTabEntry(__s('Asset-User history', 'assetuserhistory'));
    }

    /**
     * @param CommonGLPI $item
     * @param int $tabnum
     * @param int $withtemplate
     * @return true
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        /** @var CommonDBTM $item */
        $profile = new self();
        $profile->showForm($item->getID());
        return true;
    }

    /**
     * @param $ID
     * @param array $options
     * @return bool
     */
    public function showForm($ID, array $options = []): bool
    {
        if (!self::canView()) {
            return false;
        }

        $profile = new GlpiProfile();
        $profile->getFromDB($ID);

        TemplateRenderer::getInstance()->display('@assetuserhistory/profile.html.twig', [
            "item" => $profile,
            "rights" => [
                [
                    'itemtype' => History::class,
                    'label' => History::getTypeName(),
                    'field' => History::$rightname,
                ],
            ],
            "title" => __s('View asset-user history', 'assetuserhistory')
        ]);

        return true;
    }
}