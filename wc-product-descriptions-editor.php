<?php
/**
 * Plugin Name:       WC Product Descriptions Editor
 * Description:       لیست همه محصولات ووکامرس با ویرایش درجا توضیحات کوتاه و توضیحات اصلی.
 * Version:           1.3.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            WC Product Descriptions Editor
 * License:           GPL-2.0-or-later
 * Text Domain:       wc-product-descriptions-editor
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WCPDE_VERSION', '1.3.2');
define('WCPDE_FILE', __FILE__);
define('WCPDE_PATH', plugin_dir_path(__FILE__));
define('WCPDE_URL', plugin_dir_url(__FILE__));

require_once WCPDE_PATH . 'includes/class-wcpde-plugin.php';

/**
 * Bootstrap plugin.
 */
function wcpde_bootstrap(): void
{
    WCPDE_Plugin::instance();
}

add_action('plugins_loaded', 'wcpde_bootstrap');
