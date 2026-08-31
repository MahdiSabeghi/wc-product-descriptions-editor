<?php
/**
 * Plugin bootstrap.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);

            return;
        }

        require_once WCPDE_PATH . 'includes/class-wcpde-product-query.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-spreadsheet.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-import-export.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-ai-settings.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-ai-table.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-ai-client.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-ai-service.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-admin.php';
        require_once WCPDE_PATH . 'includes/class-wcpde-rest.php';

        WCPDE_Admin::instance();
        WCPDE_REST::instance();
        WCPDE_Import_Export::instance();

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_table_styles']);
    }

    public static function enqueue_frontend_table_styles(): void
    {
        if (!is_product()) {
            return;
        }

        wp_enqueue_style(
            'wcpde-frontend-table',
            WCPDE_URL . 'assets/css/frontend-table.css',
            [],
            WCPDE_VERSION
        );
    }

    public function woocommerce_missing_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('افزونه WC Product Descriptions Editor به ووکامرس نیاز دارد.', 'wc-product-descriptions-editor')
            . '</p></div>';
    }
}
