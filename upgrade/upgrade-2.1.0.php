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
 * 2.0.0 -> 2.1.0: the configure-page review-request line.
 *
 * Existing installations have no install date recorded, so their 21-day
 * quiet period starts at this upgrade rather than showing the line at once.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_1_0($module)
{
    require_once dirname(__FILE__) . '/../classes/MegVentureReviewNudge.php';

    return MegVentureReviewNudge::ensureInstalledAt();
}
