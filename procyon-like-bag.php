<?php
/**
 * Plugin Name: Procyon Like Bag
 * Description: API-only favorites bag for WooCommerce with guest token session and persistent storage for logged-in users.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: bartek5186
 */

if (!defined('ABSPATH')) exit;

define('PROCYON_LIKE_BAG_VER', '0.1.0');
define('PROCYON_LIKE_BAG_PATH', plugin_dir_path(__FILE__));
define('PROCYON_LIKE_BAG_TABLE_VERSION', '1');

require_once PROCYON_LIKE_BAG_PATH . 'includes/class-store.php';
require_once PROCYON_LIKE_BAG_PATH . 'includes/class-rest.php';

register_activation_hook(__FILE__, function () {
    \Procyon\LikeBag\Store::install_table();
    \Procyon\LikeBag\Store::ensure_cleanup_schedule();
});

register_deactivation_hook(__FILE__, function () {
    \Procyon\LikeBag\Store::clear_cleanup_schedule();
});

add_action('plugins_loaded', function () {
    $table_version = (string) get_option('procyon_like_bag_table_version', '');
    if ($table_version !== PROCYON_LIKE_BAG_TABLE_VERSION) {
        \Procyon\LikeBag\Store::install_table();
    }

    \Procyon\LikeBag\Store::init_hooks();
    \Procyon\LikeBag\Rest::init();
});
