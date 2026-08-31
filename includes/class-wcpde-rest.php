<?php
/**
 * REST API for inline description saves + AI generation.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_REST
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
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(
            'wcpde/v1',
            '/products/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_product'],
                'permission_callback' => [$this, 'can_edit_products'],
                'args'                => [
                    'id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                ],
            ]
        );

        register_rest_route(
            'wcpde/v1',
            '/products/(?P<id>\d+)/ai-excerpt',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'generate_ai_excerpt'],
                'permission_callback' => [$this, 'can_edit_products'],
                'args'                => [
                    'id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                ],
            ]
        );
    }

    public function can_edit_products(): bool
    {
        return current_user_can('edit_products') || current_user_can('manage_woocommerce');
    }

    public function update_product(WP_REST_Request $request): WP_REST_Response
    {
        $product_id = (int) $request->get_param('id');

        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('محصول یافت نشد.', 'wc-product-descriptions-editor')],
                404
            );
        }

        if (!current_user_can('edit_post', $product_id)) {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('دسترسی ویرایش این محصول را ندارید.', 'wc-product-descriptions-editor')],
                403
            );
        }

        $params = $request->get_json_params();

        if (!is_array($params)) {
            $params = [];
        }

        $update = ['ID' => $product_id];

        if (array_key_exists('excerpt', $params)) {
            $update['post_excerpt'] = WCPDE_AI_Table::sanitize_html((string) $params['excerpt']);
        }

        if (array_key_exists('content', $params)) {
            $update['post_content'] = wp_kses_post((string) $params['content']);
        }

        if (count($update) <= 1) {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('فیلدی برای ذخیره ارسال نشده است.', 'wc-product-descriptions-editor')],
                400
            );
        }

        $result = wp_update_post($update, true);

        if (is_wp_error($result)) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $result->get_error_message(),
                ],
                422
            );
        }

        clean_post_cache($product_id);

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => __('ذخیره شد.', 'wc-product-descriptions-editor'),
                'product' => [
                    'id'      => $product_id,
                    'excerpt' => get_post_field('post_excerpt', $product_id),
                    'content' => get_post_field('post_content', $product_id),
                ],
            ],
            200
        );
    }

    public function generate_ai_excerpt(WP_REST_Request $request): WP_REST_Response
    {
        $product_id = (int) $request->get_param('id');

        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('محصول یافت نشد.', 'wc-product-descriptions-editor')],
                404
            );
        }

        if (!current_user_can('edit_post', $product_id)) {
            return new WP_REST_Response(
                ['success' => false, 'message' => __('دسترسی ویرایش این محصول را ندارید.', 'wc-product-descriptions-editor')],
                403
            );
        }

        $params = $request->get_json_params();
        $excerpt = is_array($params) ? (string) ($params['excerpt'] ?? '') : '';

        $service = new WCPDE_AI_Service();
        $result  = $service->generate_short_description_table($product_id, $excerpt);

        if (empty($result['success'])) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => (string) ($result['message'] ?? __('خطای AI', 'wc-product-descriptions-editor')),
                ],
                422
            );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => (string) ($result['message'] ?? ''),
                'html'    => (string) ($result['html'] ?? ''),
                'rows'    => $result['rows'] ?? [],
            ],
            200
        );
    }
}
