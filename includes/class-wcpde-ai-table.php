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

        $rows = $payload['rows'] ?? $payload['features'] ?? $payload['items'] ?? null;

        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
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
