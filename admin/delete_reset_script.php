<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Delete Reset Script
 *
 * One-click handler for admin_reset_script_warning()'s "Delete it now" link
 * (admin/includes/admin_helpers.php) — deletes LUMORA_ROOT/reset-password.php
 * (the emergency, no-login-required password reset) once an admin is done
 * with it. Safe to require login here even though reset-password.php itself
 * never checks one: unlike that page, this one only exists to be reached by
 * an admin who is already authenticated.
 *
 * @package    LumoraGallery
 * @subpackage Admin
 * @author     Ariane
 * @copyright  Copyright (c) 2026 Ariane
 * @license    GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 * @link       https://coding.unloved-heart.net/scripts/lumoragallery
 * @source     https://github.com/intothisshadow/LumoraGallery
 * @since      1.17.0
 */
define('LUMORA_ENTRY', true);
require_once dirname(__DIR__) . '/include/bootstrap.php';

lumora_require_permission('site_configuration');

if (isset($_GET['csrf_token']) && hash_equals(lumora_csrf_token(), (string) $_GET['csrf_token'])) {
    $reset_script_path = LUMORA_ROOT . 'reset-password.php';
    if (is_file($reset_script_path)) {
        unlink($reset_script_path);
    }
}

lumora_redirect(lumora_base_url() . 'admin/dashboard.php');
