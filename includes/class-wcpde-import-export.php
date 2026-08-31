<?php
/**
 * Excel template export + import handlers.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_Import_Export
{
    private const ACTION_EXPORT = 'wcpde_export_excel';
    private const ACTION_IMPORT = 'wcpde_import_excel';

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
        add_action('admin_post_' . self::ACTION_EXPORT, [$this, 'handle_export']);
        add_action('admin_post_' . self::ACTION_IMPORT, [$this, 'handle_import']);
    }

    public static function export_url(array $filters): string
    {
        $args = array_merge(
            WCPDE_Product_Query::filters_to_query_args($filters),
            [
                'action' => self::ACTION_EXPORT,
                '_wpnonce' => wp_create_nonce(self::ACTION_EXPORT),
            ]
        );

        return admin_url('admin-post.php?' . http_build_query($args));
    }

    public function handle_export(): void
    {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'wc-product-descriptions-editor'));
        }

        check_admin_referer(self::ACTION_EXPORT);

        $filters  = WCPDE_Product_Query::filters_from_request();
        $products = WCPDE_Product_Query::query_all($filters);
        $rows     = [];

        foreach ($products as $product) {
            $rows[] = [
                WCPDE_Spreadsheet::COL_NAME    => $product->get_name(),
                WCPDE_Spreadsheet::COL_EXCERPT => '',
                WCPDE_Spreadsheet::COL_CONTENT => '',
            ];
        }

        $filename = 'product-descriptions-' . gmdate('Y-m-d-His') . '.xlsx';
        WCPDE_Spreadsheet::download_xlsx($rows, $filename);
    }

    public function handle_import(): void
    {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'wc-product-descriptions-editor'));
        }

        check_admin_referer(self::ACTION_IMPORT);

        $redirect = admin_url('admin.php?page=wcpde-descriptions');

        if (empty($_FILES['wcpde_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['wcpde_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('wcpde_import', 'no_file', $redirect));
            exit;
        }

        $tmp  = (string) $_FILES['wcpde_file']['tmp_name'];
        $name = sanitize_file_name((string) ($_FILES['wcpde_file']['name'] ?? 'upload.xlsx'));

        try {
            $parsed = WCPDE_Spreadsheet::parse_upload($tmp, $name);
        } catch (Throwable $e) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'wcpde_import' => 'error',
                        'wcpde_msg'    => rawurlencode($e->getMessage()),
                    ],
                    $redirect
                )
            );
            exit;
        }

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($parsed as $row) {
            $title = trim((string) ($row[WCPDE_Spreadsheet::COL_NAME] ?? ''));

            if ($title === '') {
                ++$skipped;
                continue;
            }

            $product_id = WCPDE_Product_Query::find_product_id_by_title($title);

            if ($product_id <= 0) {
                ++$skipped;
                continue;
            }

            if (!current_user_can('edit_post', $product_id)) {
                ++$failed;
                continue;
            }

            $result = wp_update_post(
                [
                    'ID'           => $product_id,
                    'post_excerpt' => wp_kses_post((string) ($row[WCPDE_Spreadsheet::COL_EXCERPT] ?? '')),
                    'post_content' => wp_kses_post((string) ($row[WCPDE_Spreadsheet::COL_CONTENT] ?? '')),
                ],
                true
            );

            if (is_wp_error($result)) {
                ++$failed;
                continue;
            }

            clean_post_cache($product_id);
            ++$updated;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'wcpde_import' => 'done',
                    'wcpde_updated' => $updated,
                    'wcpde_skipped' => $skipped,
                    'wcpde_failed'  => $failed,
                ],
                $redirect
            )
        );
        exit;
    }

    public static function render_notice(): void
    {
        if (!isset($_GET['wcpde_import'])) {
            return;
        }

        $status = sanitize_key((string) $_GET['wcpde_import']);

        if ($status === 'done') {
            $updated = (int) ($_GET['wcpde_updated'] ?? 0);
            $skipped = (int) ($_GET['wcpde_skipped'] ?? 0);
            $failed  = (int) ($_GET['wcpde_failed'] ?? 0);

            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(
                    sprintf(
                        /* translators: 1: updated 2: skipped 3: failed */
                        __('ایمپورت انجام شد: %1$s محصول به‌روزرسانی شد، %2$s ردیف رد شد (نام یافت نشد / خالی)، %3$s خطا.', 'wc-product-descriptions-editor'),
                        number_format_i18n($updated),
                        number_format_i18n($skipped),
                        number_format_i18n($failed)
                    )
                )
                . '</p></div>';

            return;
        }

        if ($status === 'no_file') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('فایلی انتخاب نشده است.', 'wc-product-descriptions-editor')
                . '</p></div>';

            return;
        }

        if ($status === 'error') {
            $msg = isset($_GET['wcpde_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['wcpde_msg'])) : '';

            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html($msg !== '' ? $msg : __('ایمپورت ناموفق بود.', 'wc-product-descriptions-editor'))
                . '</p></div>';
        }
    }
}
