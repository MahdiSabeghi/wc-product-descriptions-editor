<?php
/**
 * Product feature table HTML builder + sanitizer.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_AI_Table
{
    public static function build_from_payload(mixed $payload): string
    {
        $matrix = self::matrix_from_ai_payload($payload);

        if ($matrix !== null) {
            return self::build_matrix_html($matrix);
        }

        return self::build_html(self::rows_from_ai_payload($payload));
    }

    /**
     * @param array{columns: string[], rows: array<int, array{label: string, values: string[]}>} $matrix
     */
    public static function build_matrix_html(array $matrix): string
    {
        $columns = $matrix['columns'];
        $rows    = $matrix['rows'];

        if ($columns === [] || $rows === []) {
            return '';
        }

        $html = '<table class="wcpde-product-table wcpde-product-table--matrix"><thead><tr><th scope="col"></th>';

        foreach ($columns as $column) {
            $html .= '<th scope="col">' . esc_html($column) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><th scope="row">' . esc_html($row['label']) . '</th>';

            foreach ($row['values'] as $value) {
                $html .= '<td>' . esc_html($value) . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return self::sanitize_html($html);
    }

    /**
     * @return array{columns: string[], rows: array<int, array{label: string, values: string[]}>}|null
     */
    public static function matrix_from_ai_payload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $layout = (string) ($payload['layout'] ?? '');

        if ($layout === '' && isset($payload['columns'], $payload['rows']) && is_array($payload['columns']) && is_array($payload['rows'])) {
            $layout = 'matrix';
        }

        if ($layout !== 'matrix') {
            return null;
        }

        $columns = $payload['columns'] ?? [];

        if (!is_array($columns)) {
            return null;
        }

        $columns = array_values(
            array_filter(
                array_map(static fn($column): string => trim((string) $column), $columns),
                static fn(string $column): bool => $column !== ''
            )
        );

        if ($columns === []) {
            return null;
        }

        $raw_rows = $payload['rows'] ?? [];

        if (!is_array($raw_rows) || $raw_rows === []) {
            return null;
        }

        $rows = [];

        foreach ($raw_rows as $raw_row) {
            if (!is_array($raw_row)) {
                continue;
            }

            $label = trim((string) ($raw_row['label'] ?? $raw_row['row'] ?? ''));

            if ($label === '') {
                continue;
            }

            $values = [];

            if (isset($raw_row['values']) && is_array($raw_row['values'])) {
                $values = array_map(static fn($value): string => trim((string) $value), $raw_row['values']);
            } elseif (isset($raw_row['cells']) && is_array($raw_row['cells'])) {
                foreach ($columns as $column) {
                    $values[] = trim((string) ($raw_row['cells'][$column] ?? ''));
                }
            } else {
                continue;
            }

            while (count($values) < count($columns)) {
                $values[] = '';
            }

            $rows[] = [
                'label'  => $label,
                'values' => array_slice($values, 0, count($columns)),
            ];
        }

        if ($rows === []) {
            return null;
        }

        return [
            'columns' => $columns,
            'rows'    => $rows,
        ];
    }

    /**
     * @param array<int, array{label:string, value:string}> $rows
     */
    public static function build_html(array $rows): string
    {
        $rows = array_values(
            array_filter(
                $rows,
                static fn(array $row): bool => trim($row['label'] ?? '') !== '' || trim($row['value'] ?? '') !== ''
            )
        );

        if ($rows === []) {
            return '';
        }

        $html = '<table class="wcpde-product-table"><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<th scope="row">' . esc_html((string) ($row['label'] ?? '')) . '</th>'
                . '<td>' . esc_html((string) ($row['value'] ?? '')) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return self::sanitize_html($html);
    }

    public static function sanitize_html(string $html): string
    {
        $allowed = [
            'table'  => ['class' => true],
            'tbody'  => [],
            'thead'  => [],
            'tr'     => [],
            'th'     => ['scope' => true, 'colspan' => true, 'rowspan' => true],
            'td'     => ['colspan' => true, 'rowspan' => true],
            'br'     => [],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'p'      => [],
        ];

        return wp_kses($html, $allowed);
    }

    /**
     * @return array<int, array{label:string, value:string}>
     */
    public static function rows_from_ai_payload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (($payload['layout'] ?? '') === 'matrix') {
            return [];
        }

        $rows = $payload['rows'] ?? $payload['features'] ?? $payload['items'] ?? null;

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row['values']) || isset($row['cells'])) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? $row['name'] ?? $row['key'] ?? ''));
            $value = trim((string) ($row['value'] ?? $row['val'] ?? ''));

            if ($label === '' && $value === '') {
                continue;
            }

            $normalized[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $normalized;
    }
}
