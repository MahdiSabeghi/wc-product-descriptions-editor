<?php
/**
 * Admin list + inline editor UI.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_Admin
{
    private const PAGE_SLUG = 'wcpde-descriptions';

    /** @var int[] */
    private const PER_PAGE_OPTIONS = WCPDE_Product_Query::PER_PAGE_OPTIONS;

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
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_wcpde_save_ai_settings', [$this, 'handle_save_ai_settings']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('ویرایش توضیحات محصولات', 'wc-product-descriptions-editor'),
            __('توضیحات محصولات', 'wc-product-descriptions-editor'),
            'edit_products',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'wcpde-vazirmatn',
            'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css',
            [],
            '33.003'
        );

        wp_enqueue_style(
            'wcpde-admin',
            WCPDE_URL . 'assets/css/admin.css',
            ['wcpde-vazirmatn'],
            WCPDE_VERSION
        );

        wp_enqueue_script(
            'wcpde-admin',
            WCPDE_URL . 'assets/js/admin.js',
            [],
            WCPDE_VERSION,
            true
        );

        wp_localize_script(
            'wcpde-admin',
            'wcpdeAdmin',
            [
                'restUrl' => esc_url_raw(rest_url('wcpde/v1/products/')),
                'aiUrl'   => esc_url_raw(rest_url('wcpde/v1/products/')),
                'nonce'   => wp_create_nonce('wp_rest'),
                'aiReady' => WCPDE_AI_Settings::is_configured(),
                'i18n'    => [
                    'saving'    => __('در حال ذخیره…', 'wc-product-descriptions-editor'),
                    'saved'     => __('ذخیره شد', 'wc-product-descriptions-editor'),
                    'error'     => __('خطا در ذخیره', 'wc-product-descriptions-editor'),
                    'save'      => __('ذخیره', 'wc-product-descriptions-editor'),
                    'dirty'     => __('تغییر ذخیره‌نشده', 'wc-product-descriptions-editor'),
                    'aiLoading' => __('در حال تولید جدول با AI…', 'wc-product-descriptions-editor'),
                    'aiDone'    => __('جدول در توضیحات کوتاه قرار گرفت', 'wc-product-descriptions-editor'),
                    'aiError'   => __('خطا در تولید AI', 'wc-product-descriptions-editor'),
                    'aiNeedKey' => __('کلید API متیس را در تنظیمات وارد کنید.', 'wc-product-descriptions-editor'),
                    'aiNeedExcerpt' => __('ابتدا متن توضیحات کوتاه را وارد کنید.', 'wc-product-descriptions-editor'),
                    'preview'   => __('پیش‌نمایش', 'wc-product-descriptions-editor'),
                ],
            ]
        );
    }

    public function handle_save_ai_settings(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('edit_products')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'wc-product-descriptions-editor'));
        }

        check_admin_referer('wcpde_save_ai_settings');

        if (!empty($_POST['wcpde_clear_api_key'])) {
            WCPDE_AI_Settings::clear_api_key();
        } else {
            WCPDE_AI_Settings::save([
                'api_key'  => wp_unslash((string) ($_POST['wcpde_api_key'] ?? '')),
                'base_url' => wp_unslash((string) ($_POST['wcpde_base_url'] ?? '')),
                'model'    => wp_unslash((string) ($_POST['wcpde_model'] ?? '')),
            ]);
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => self::PAGE_SLUG, 'wcpde_ai_saved' => '1'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    private function render_ai_settings_notice(): void
    {
        if (!isset($_GET['wcpde_ai_saved'])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('تنظیمات AI ذخیره شد.', 'wc-product-descriptions-editor')
            . '</p></div>';
    }

    private function render_ai_settings_panel(): void
    {
        $settings = WCPDE_AI_Settings::get();
        $configured = WCPDE_AI_Settings::is_configured();
        ?>
        <section class="wcpde-ai-settings">
            <div class="wcpde-ai-settings__head">
                <div>
                    <h2><?php esc_html_e('تنظیمات AI — متیس', 'wc-product-descriptions-editor'); ?></h2>
                    <p><?php esc_html_e('کلید API متیس را وارد کنید تا جدول ویژگی‌ها با مدل gpt-4o-mini ساخته شود.', 'wc-product-descriptions-editor'); ?></p>
                </div>
                <span class="wcpde-ai-settings__badge <?php echo $configured ? 'is-on' : 'is-off'; ?>">
                    <?php echo $configured
                        ? esc_html__('متصل', 'wc-product-descriptions-editor')
                        : esc_html__('نیاز به API Key', 'wc-product-descriptions-editor'); ?>
                </span>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcpde-ai-settings__form">
                <?php wp_nonce_field('wcpde_save_ai_settings'); ?>
                <input type="hidden" name="action" value="wcpde_save_ai_settings" />
                <div class="wcpde-ai-settings__grid">
                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('کلید API متیس', 'wc-product-descriptions-editor'); ?></span>
                        <input
                            type="password"
                            name="wcpde_api_key"
                            value=""
                            placeholder="<?php echo $configured ? esc_attr__('•••••••• (برای تغییر، کلید جدید وارد کنید)', 'wc-product-descriptions-editor') : esc_attr__('Metis-API-Key', 'wc-product-descriptions-editor'); ?>"
                            autocomplete="off"
                        />
                    </label>
                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('آدرس API', 'wc-product-descriptions-editor'); ?></span>
                        <input type="url" name="wcpde_base_url" value="<?php echo esc_attr($settings['base_url']); ?>" />
                    </label>
                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('مدل', 'wc-product-descriptions-editor'); ?></span>
                        <input type="text" name="wcpde_model" value="<?php echo esc_attr($settings['model']); ?>" readonly />
                    </label>
                </div>
                <div class="wcpde-ai-settings__actions">
                    <button type="submit" class="button button-primary wcpde-btn wcpde-btn--primary">
                        <?php esc_html_e('ذخیره تنظیمات AI', 'wc-product-descriptions-editor'); ?>
                    </button>
                    <?php if ($configured) : ?>
                        <button type="submit" name="wcpde_clear_api_key" value="1" class="button wcpde-btn wcpde-btn--ghost">
                            <?php esc_html_e('حذف کلید API', 'wc-product-descriptions-editor'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
            <div class="wcpde-ai-settings__palette" aria-hidden="true">
                <span style="background:#C6ACFF"></span>
                <span style="background:#E8DCFF"></span>
                <span style="background:#FFCCB4"></span>
                <span style="background:#FFF0E8"></span>
            </div>
        </section>
        <?php
    }

    public function render_page(): void
    {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'wc-product-descriptions-editor'));
        }

        WCPDE_Import_Export::render_notice();
        $this->render_ai_settings_notice();

        $filters    = WCPDE_Product_Query::filters_from_request();
        $result     = WCPDE_Product_Query::query_paginated($filters);
        $products   = $result['products'];
        $total      = $result['total'];
        $pages      = $result['pages'];
        $paged      = (int) $filters['paged'];
        $query_args = WCPDE_Product_Query::filters_to_query_args($filters);
        $export_url = WCPDE_Import_Export::export_url($filters);

        $categories = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        $tags = get_terms(
            [
                'taxonomy'   => 'product_tag',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        if (is_wp_error($categories)) {
            $categories = [];
        }

        if (is_wp_error($tags)) {
            $tags = [];
        }
        ?>
        <div class="wrap wcpde-wrap">
            <header class="wcpde-hero">
                <div>
                    <h1><?php esc_html_e('ویرایش توضیحات محصولات', 'wc-product-descriptions-editor'); ?></h1>
                    <p><?php esc_html_e('توضیحات کوتاه و اصلی همه محصولات را در یک جدول ویرایش و ذخیره کنید.', 'wc-product-descriptions-editor'); ?></p>
                </div>
                <div class="wcpde-hero__stat">
                    <strong><?php echo esc_html(number_format_i18n($total)); ?></strong>
                    <span><?php esc_html_e('محصول', 'wc-product-descriptions-editor'); ?></span>
                </div>
            </header>

            <?php $this->render_ai_settings_panel(); ?>

            <form method="get" class="wcpde-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />

                <div class="wcpde-filters__grid">
                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('نام محصول', 'wc-product-descriptions-editor'); ?></span>
                        <input type="search" name="s" value="<?php echo esc_attr((string) $filters['s']); ?>" placeholder="<?php esc_attr_e('جستجو…', 'wc-product-descriptions-editor'); ?>" />
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('SKU', 'wc-product-descriptions-editor'); ?></span>
                        <input type="search" name="sku" value="<?php echo esc_attr((string) $filters['sku']); ?>" placeholder="<?php esc_attr_e('کد SKU', 'wc-product-descriptions-editor'); ?>" />
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('دسته‌بندی', 'wc-product-descriptions-editor'); ?></span>
                        <select name="category">
                            <option value=""><?php esc_html_e('همه دسته‌ها', 'wc-product-descriptions-editor'); ?></option>
                            <?php foreach ($categories as $term) : ?>
                                <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected((int) $filters['category'], (int) $term->term_id); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('برچسب', 'wc-product-descriptions-editor'); ?></span>
                        <select name="tag">
                            <option value=""><?php esc_html_e('همه برچسب‌ها', 'wc-product-descriptions-editor'); ?></option>
                            <?php foreach ($tags as $term) : ?>
                                <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected((int) $filters['tag'], (int) $term->term_id); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('نوع محصول', 'wc-product-descriptions-editor'); ?></span>
                        <select name="product_type">
                            <option value=""><?php esc_html_e('همه انواع', 'wc-product-descriptions-editor'); ?></option>
                            <option value="simple" <?php selected($filters['product_type'], 'simple'); ?>><?php esc_html_e('ساده', 'wc-product-descriptions-editor'); ?></option>
                            <option value="variable" <?php selected($filters['product_type'], 'variable'); ?>><?php esc_html_e('متغیر', 'wc-product-descriptions-editor'); ?></option>
                            <option value="grouped" <?php selected($filters['product_type'], 'grouped'); ?>><?php esc_html_e('گروهی', 'wc-product-descriptions-editor'); ?></option>
                            <option value="external" <?php selected($filters['product_type'], 'external'); ?>><?php esc_html_e('خارجی/وابسته', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('موجودی', 'wc-product-descriptions-editor'); ?></span>
                        <select name="stock_status">
                            <option value=""><?php esc_html_e('همه', 'wc-product-descriptions-editor'); ?></option>
                            <option value="instock" <?php selected($filters['stock_status'], 'instock'); ?>><?php esc_html_e('موجود', 'wc-product-descriptions-editor'); ?></option>
                            <option value="outofstock" <?php selected($filters['stock_status'], 'outofstock'); ?>><?php esc_html_e('ناموجود', 'wc-product-descriptions-editor'); ?></option>
                            <option value="onbackorder" <?php selected($filters['stock_status'], 'onbackorder'); ?>><?php esc_html_e('پیش‌سفارش', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('وضعیت انتشار', 'wc-product-descriptions-editor'); ?></span>
                        <select name="post_status">
                            <option value=""><?php esc_html_e('همه', 'wc-product-descriptions-editor'); ?></option>
                            <option value="publish" <?php selected($filters['post_status'], 'publish'); ?>><?php esc_html_e('منتشرشده', 'wc-product-descriptions-editor'); ?></option>
                            <option value="draft" <?php selected($filters['post_status'], 'draft'); ?>><?php esc_html_e('پیش‌نویس', 'wc-product-descriptions-editor'); ?></option>
                            <option value="pending" <?php selected($filters['post_status'], 'pending'); ?>><?php esc_html_e('در انتظار', 'wc-product-descriptions-editor'); ?></option>
                            <option value="private" <?php selected($filters['post_status'], 'private'); ?>><?php esc_html_e('خصوصی', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('ویژه', 'wc-product-descriptions-editor'); ?></span>
                        <select name="featured">
                            <option value=""><?php esc_html_e('همه', 'wc-product-descriptions-editor'); ?></option>
                            <option value="yes" <?php selected($filters['featured'], 'yes'); ?>><?php esc_html_e('فقط ویژه', 'wc-product-descriptions-editor'); ?></option>
                            <option value="no" <?php selected($filters['featured'], 'no'); ?>><?php esc_html_e('غیر ویژه', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('مرتب‌سازی', 'wc-product-descriptions-editor'); ?></span>
                        <select name="orderby">
                            <option value="title" <?php selected($filters['orderby'], 'title'); ?>><?php esc_html_e('نام', 'wc-product-descriptions-editor'); ?></option>
                            <option value="date" <?php selected($filters['orderby'], 'date'); ?>><?php esc_html_e('تاریخ', 'wc-product-descriptions-editor'); ?></option>
                            <option value="modified" <?php selected($filters['orderby'], 'modified'); ?>><?php esc_html_e('آخرین ویرایش', 'wc-product-descriptions-editor'); ?></option>
                            <option value="id" <?php selected($filters['orderby'], 'id'); ?>><?php esc_html_e('شناسه', 'wc-product-descriptions-editor'); ?></option>
                            <option value="menu_order" <?php selected($filters['orderby'], 'menu_order'); ?>><?php esc_html_e('ترتیب منو', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('ترتیب', 'wc-product-descriptions-editor'); ?></span>
                        <select name="order">
                            <option value="ASC" <?php selected($filters['order'], 'ASC'); ?>><?php esc_html_e('صعودی', 'wc-product-descriptions-editor'); ?></option>
                            <option value="DESC" <?php selected($filters['order'], 'DESC'); ?>><?php esc_html_e('نزولی', 'wc-product-descriptions-editor'); ?></option>
                        </select>
                    </label>

                    <label class="wcpde-field-wrap">
                        <span><?php esc_html_e('تعداد در صفحه', 'wc-product-descriptions-editor'); ?></span>
                        <select name="per_page">
                            <?php foreach (self::PER_PAGE_OPTIONS as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option); ?>" <?php selected((int) $filters['per_page'], $option); ?>>
                                    <?php echo esc_html(number_format_i18n($option)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="wcpde-filters__actions">
                    <button type="submit" class="button button-primary wcpde-btn wcpde-btn--primary">
                        <?php esc_html_e('اعمال فیلتر', 'wc-product-descriptions-editor'); ?>
                    </button>
                    <a class="button wcpde-btn wcpde-btn--ghost" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                        <?php esc_html_e('پاک کردن فیلترها', 'wc-product-descriptions-editor'); ?>
                    </a>
                </div>
            </form>

            <section class="wcpde-excel">
                <div class="wcpde-excel__head">
                    <div>
                        <h2><?php esc_html_e('اکسل — دانلود قالب و آپلود', 'wc-product-descriptions-editor'); ?></h2>
                        <p><?php esc_html_e('فایل شامل سه ستون است: نام محصول، توضیحات کوتاه، توضیحات اصلی. قالب با فیلترهای فعلی پر می‌شود؛ فقط محصولاتی که نامشان در ووکامرس وجود دارد به‌روزرسانی می‌شوند.', 'wc-product-descriptions-editor'); ?></p>
                    </div>
                </div>
                <div class="wcpde-excel__grid">
                    <div class="wcpde-excel__card">
                        <h3><?php esc_html_e('۱) دانلود قالب', 'wc-product-descriptions-editor'); ?></h3>
                        <p><?php esc_html_e('ستون نام محصول از ووکامرس (با فیلترهای بالا) پر می‌شود؛ دو ستون دیگر خالی می‌ماند.', 'wc-product-descriptions-editor'); ?></p>
                        <a class="button button-primary wcpde-btn wcpde-btn--primary" href="<?php echo esc_url($export_url); ?>">
                            <?php esc_html_e('دانلود فایل اکسل', 'wc-product-descriptions-editor'); ?>
                        </a>
                    </div>
                    <div class="wcpde-excel__card">
                        <h3><?php esc_html_e('۲) آپلود و اعمال', 'wc-product-descriptions-editor'); ?></h3>
                        <p><?php esc_html_e('فایل xlsx یا csv را بارگذاری کنید. اگر نام محصول موجود نباشد، آن ردیف نادیده گرفته می‌شود.', 'wc-product-descriptions-editor'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wcpde-import-form">
                            <?php wp_nonce_field('wcpde_import_excel'); ?>
                            <input type="hidden" name="action" value="wcpde_import_excel" />
                            <input type="file" name="wcpde_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required />
                            <button type="submit" class="button button-primary wcpde-btn wcpde-btn--primary">
                                <?php esc_html_e('آپلود و به‌روزرسانی', 'wc-product-descriptions-editor'); ?>
                            </button>
                        </form>
                    </div>
                </div>
                <ul class="wcpde-excel__cols">
                    <li><strong><?php echo esc_html(WCPDE_Spreadsheet::HEADERS[WCPDE_Spreadsheet::COL_NAME]); ?></strong></li>
                    <li><strong><?php echo esc_html(WCPDE_Spreadsheet::HEADERS[WCPDE_Spreadsheet::COL_EXCERPT]); ?></strong></li>
                    <li><strong><?php echo esc_html(WCPDE_Spreadsheet::HEADERS[WCPDE_Spreadsheet::COL_CONTENT]); ?></strong></li>
                </ul>
            </section>

            <?php if ($products === []) : ?>
                <div class="wcpde-empty">
                    <p><?php esc_html_e('محصولی با این فیلتر یافت نشد.', 'wc-product-descriptions-editor'); ?></p>
                </div>
            <?php else : ?>
                <div class="wcpde-toolbar">
                    <span>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: from, 2: to, 3: total */
                                __('نمایش %1$s–%2$s از %3$s', 'wc-product-descriptions-editor'),
                                number_format_i18n(min($total, (($paged - 1) * (int) $filters['per_page']) + 1)),
                                number_format_i18n(min($total, $paged * (int) $filters['per_page'])),
                                number_format_i18n($total)
                            )
                        );
                        ?>
                    </span>
                    <span class="wcpde-hint"><?php esc_html_e('Ctrl+Enter برای ذخیره سریع', 'wc-product-descriptions-editor'); ?></span>
                </div>

                <div class="wcpde-table-wrap">
                    <table class="wcpde-table">
                        <thead>
                            <tr>
                                <th class="wcpde-col-product"><?php esc_html_e('محصول', 'wc-product-descriptions-editor'); ?></th>
                                <th class="wcpde-col-excerpt"><?php esc_html_e('توضیحات کوتاه', 'wc-product-descriptions-editor'); ?></th>
                                <th class="wcpde-col-content"><?php esc_html_e('توضیحات اصلی', 'wc-product-descriptions-editor'); ?></th>
                                <th class="wcpde-col-actions"><?php esc_html_e('عملیات', 'wc-product-descriptions-editor'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product) : ?>
                                <?php $this->render_row($product); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php $this->render_pagination($paged, $pages, $query_args); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_row(WC_Product $product): void
    {
        $product_id = (int) $product->get_id();
        $post       = get_post($product_id);

        if (!$post instanceof WP_Post) {
            return;
        }

        $thumb     = $product->get_image('thumbnail', ['class' => 'wcpde-thumb']);
        $edit_link = get_edit_post_link($product_id, 'raw');
        $sku       = $product->get_sku();
        $type      = $product->get_type();
        $status    = $product->get_status();
        $stock     = $product->get_stock_status();
        ?>
        <tr class="wcpde-row" data-product-id="<?php echo esc_attr((string) $product_id); ?>">
            <td class="wcpde-col-product">
                <div class="wcpde-product-cell">
                    <?php echo $thumb !== '' ? wp_kses_post($thumb) : '<span class="wcpde-no-thumb">—</span>'; ?>
                    <div>
                        <strong><?php echo esc_html($product->get_name()); ?></strong>
                        <div class="wcpde-badges">
                            <span class="wcpde-badge wcpde-badge--type"><?php echo esc_html($type); ?></span>
                            <span class="wcpde-badge wcpde-badge--status"><?php echo esc_html($status); ?></span>
                            <span class="wcpde-badge wcpde-badge--stock"><?php echo esc_html($stock); ?></span>
                        </div>
                        <div class="wcpde-meta">
                            <span>#<?php echo esc_html((string) $product_id); ?></span>
                            <?php if ($sku !== '') : ?>
                                <span><?php echo esc_html(sprintf(__('SKU: %s', 'wc-product-descriptions-editor'), $sku)); ?></span>
                            <?php endif; ?>
                            <?php if (is_string($edit_link) && $edit_link !== '') : ?>
                                <a href="<?php echo esc_url($edit_link); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('ویرایش', 'wc-product-descriptions-editor'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </td>
            <td class="wcpde-col-excerpt">
                <div class="wcpde-excerpt-tools">
                    <button type="button" class="wcpde-ai-generate" <?php disabled(!WCPDE_AI_Settings::is_configured()); ?>>
                        <?php esc_html_e('✨ تولید جدول AI', 'wc-product-descriptions-editor'); ?>
                    </button>
                    <button type="button" class="wcpde-ai-preview-toggle" hidden>
                        <?php esc_html_e('پیش‌نمایش', 'wc-product-descriptions-editor'); ?>
                    </button>
                </div>
                <textarea
                    class="wcpde-textarea"
                    data-field="excerpt"
                    rows="5"
                    aria-label="<?php esc_attr_e('توضیحات کوتاه', 'wc-product-descriptions-editor'); ?>"
                ><?php echo esc_textarea($post->post_excerpt); ?></textarea>
                <div class="wcpde-ai-preview" hidden aria-live="polite"></div>
            </td>
            <td class="wcpde-col-content">
                <textarea
                    class="wcpde-textarea"
                    data-field="content"
                    rows="8"
                    aria-label="<?php esc_attr_e('توضیحات اصلی', 'wc-product-descriptions-editor'); ?>"
                ><?php echo esc_textarea($post->post_content); ?></textarea>
            </td>
            <td class="wcpde-col-actions">
                <button type="button" class="wcpde-save">
                    <?php esc_html_e('ذخیره', 'wc-product-descriptions-editor'); ?>
                </button>
                <span class="wcpde-status" aria-live="polite"></span>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<string, string|int> $query_args
     */
    private function render_pagination(int $current, int $total, array $query_args): void
    {
        if ($total <= 1) {
            return;
        }

        $base = add_query_arg(array_merge($query_args, ['paged' => '%#%']), admin_url('admin.php'));
        ?>
        <nav class="wcpde-pager" aria-label="<?php esc_attr_e('صفحه‌بندی', 'wc-product-descriptions-editor'); ?>">
            <?php
            echo wp_kses_post(
                paginate_links(
                    [
                        'base'      => $base,
                        'format'    => '',
                        'current'   => $current,
                        'total'     => $total,
                        'prev_text' => __('قبلی', 'wc-product-descriptions-editor'),
                        'next_text' => __('بعدی', 'wc-product-descriptions-editor'),
                        'type'      => 'list',
                    ]
                ) ?: ''
            );
            ?>
        </nav>
        <?php
    }
}
