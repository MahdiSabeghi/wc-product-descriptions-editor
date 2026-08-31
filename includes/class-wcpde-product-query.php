<?php
/**
 * WooCommerce product query helpers (filters + wc_get_products).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_Product_Query
{
    /** @var int[] */
    public const PER_PAGE_OPTIONS = [20, 50, 100];

    /**
     * @return array<string, mixed>
     */
    public static function filters_from_request(): array
    {
        $per_page = (int) ($_GET['per_page'] ?? 20);
        if (!in_array($per_page, self::PER_PAGE_OPTIONS, true)) {
            $per_page = 20;
        }

        $type = sanitize_key((string) ($_GET['product_type'] ?? ''));
        $allowed_types = ['simple', 'variable', 'grouped', 'external'];
        if ($type !== '' && !in_array($type, $allowed_types, true)) {
            $type = '';
        }

        $stock = sanitize_key((string) ($_GET['stock_status'] ?? ''));
        $allowed_stock = ['instock', 'outofstock', 'onbackorder'];
        if ($stock !== '' && !in_array($stock, $allowed_stock, true)) {
            $stock = '';
        }

        $status = sanitize_key((string) ($_GET['post_status'] ?? ''));
        $allowed_status = ['publish', 'draft', 'pending', 'private'];
        if ($status !== '' && !in_array($status, $allowed_status, true)) {
            $status = '';
        }

        $orderby = sanitize_key((string) ($_GET['orderby'] ?? 'title'));
        $allowed_orderby = ['title', 'date', 'id', 'menu_order', 'modified'];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'title';
        }

        $order = strtoupper(sanitize_key((string) ($_GET['order'] ?? 'ASC')));
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'ASC';
        }

        return [
            's'            => sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))),
            'sku'          => sanitize_text_field(wp_unslash((string) ($_GET['sku'] ?? ''))),
            'category'     => max(0, (int) ($_GET['category'] ?? 0)),
            'tag'          => max(0, (int) ($_GET['tag'] ?? 0)),
            'product_type' => $type,
            'stock_status' => $stock,
            'post_status'  => $status,
            'featured'     => isset($_GET['featured']) ? sanitize_key((string) $_GET['featured']) : '',
            'paged'        => max(1, (int) ($_GET['paged'] ?? 1)),
            'per_page'     => $per_page,
            'orderby'      => $orderby,
            'order'        => $order,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function wc_args_from_filters(array $filters, bool $paginate, int $page = 1, int $limit = 20): array
    {
        $args = [
            'orderby' => (string) ($filters['orderby'] ?? 'title'),
            'order'   => (string) ($filters['order'] ?? 'ASC'),
            'return'  => 'objects',
        ];

        if ($paginate) {
            $args['limit']    = $limit;
            $args['page']     = $page;
            $args['paginate'] = true;
        } else {
            $args['limit'] = -1;
        }

        if ((string) ($filters['post_status'] ?? '') !== '') {
            $args['status'] = (string) $filters['post_status'];
        } else {
            $args['status'] = ['publish', 'draft', 'pending', 'private'];
        }

        if ((string) ($filters['product_type'] ?? '') !== '') {
            $args['type'] = (string) $filters['product_type'];
        }

        if ((int) ($filters['category'] ?? 0) > 0) {
            $args['category'] = [(int) $filters['category']];
        }

        if ((int) ($filters['tag'] ?? 0) > 0) {
            $args['tag'] = [(int) $filters['tag']];
        }

        if ((string) ($filters['stock_status'] ?? '') !== '') {
            $args['stock_status'] = (string) $filters['stock_status'];
        }

        if ((string) ($filters['sku'] ?? '') !== '') {
            $args['sku'] = (string) $filters['sku'];
        }

        if ((string) ($filters['s'] ?? '') !== '') {
            $args['s'] = (string) $filters['s'];
        }

        if ((string) ($filters['featured'] ?? '') === 'yes') {
            $args['featured'] = true;
        } elseif ((string) ($filters['featured'] ?? '') === 'no') {
            $args['featured'] = false;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{products: WC_Product[], total: int, pages: int}
     */
    public static function query_paginated(array $filters): array
    {
        $args   = self::wc_args_from_filters(
            $filters,
            true,
            (int) ($filters['paged'] ?? 1),
            (int) ($filters['per_page'] ?? 20)
        );
        $result = wc_get_products($args);

        if (!is_object($result) || !isset($result->products)) {
            return [
                'products' => [],
                'total'    => 0,
                'pages'    => 1,
            ];
        }

        /** @var WC_Product[] $products */
        $products = is_array($result->products) ? $result->products : [];

        return [
            'products' => $products,
            'total'    => (int) ($result->total ?? 0),
            'pages'    => max(1, (int) ($result->max_num_pages ?? 1)),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return WC_Product[]
     */
    public static function query_all(array $filters): array
    {
        $args   = self::wc_args_from_filters($filters, false);
        $result = wc_get_products($args);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string|int>
     */
    public static function filters_to_query_args(array $filters): array
    {
        $args = [
            'page'     => 'wcpde-descriptions',
            'per_page' => (int) ($filters['per_page'] ?? 20),
            'orderby'  => (string) ($filters['orderby'] ?? 'title'),
            'order'    => (string) ($filters['order'] ?? 'ASC'),
        ];

        foreach (['s', 'sku', 'product_type', 'stock_status', 'post_status', 'featured'] as $key) {
            if ((string) ($filters[$key] ?? '') !== '') {
                $args[$key] = (string) $filters[$key];
            }
        }

        foreach (['category', 'tag'] as $key) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $args[$key] = (int) $filters[$key];
            }
        }

        return $args;
    }

    public static function find_product_id_by_title(string $title): int
    {
        global $wpdb;

        $title = trim($title);

        if ($title === '') {
            return 0;
        }

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                   AND post_title = %s
                   AND post_status IN ('publish','draft','pending','private')
                 LIMIT 1",
                $title
            )
        );

        return $id !== null ? (int) $id : 0;
    }
}
