<?php
/**
 * Plugin Name: SMDP - Category Attribute Relations
 * Description: Creates a DB table for mapping WooCommerce categories to attributes (and vice versa).
 * Version: 1.0.0
 * Author: Nesar
 * Text Domain: smdp-textdomain
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if (!defined('SMDP_AT_CAT_DOMAIN')) {
    define('SMDP_AT_CAT_DOMAIN', 'smdp-courier');
}

if (!defined('SMDP_AT_CAT_DIR')) {
    define('SMDP_AT_CAT_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SMDP_AT_CAT_URL')) {
    define('SMDP_AT_CAT_URL', plugin_dir_url(__FILE__));
}

if (!defined('SMDP_AT_CAT_FILE')) {
    define('SMDP_AT_CAT_FILE', __FILE__);
}

if (! class_exists('SMDP_Logger')) {
    require_once SMDP_AT_CAT_DIR . 'inc/logger.php';
}

add_action('woocommerce_init', 'smdpc_at_cat');
function smdpc_at_cat()
{
    require_once SMDP_AT_CAT_DIR . 'inc/core.php';
}