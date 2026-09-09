<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.1.0 -> 2.1.1: back-office icons move to the core's own icon set.
 *
 * Templates only — nothing in the database changes. They are re-read from disk,
 * so all this does is drop the caches that could still be serving the old markup
 * on a shop configured never to recompile.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_1_1($module)
{
    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }

    if (method_exists('Media', 'clearCache')) {
        Media::clearCache();
    }

    return true;
}
