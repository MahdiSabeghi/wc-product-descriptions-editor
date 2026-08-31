<?php
/**
 * Minimal XLSX read/write (ZipArchive + OOXML) and CSV fallback.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_Spreadsheet
{
    public const COL_NAME    = 'product_name';
    public const COL_EXCERPT = 'short_description';
    public const COL_CONTENT = 'main_description';

    /** @var string[] */
    public const HEADERS = [
        self::COL_NAME    => 'نام محصول',
        self::COL_EXCERPT => 'توضیحات کوتاه',
        self::COL_CONTENT => 'توضیحات اصلی',
    ];

    /**
     * @param array<int, array{product_name: string, short_description: string, main_description: string}> $rows
     */
    public static function download_xlsx(array $rows, string $filename): void
    {
        if (!class_exists('ZipArchive')) {
            self::download_csv($rows, preg_replace('/\.xlsx$/i', '.csv', $filename) ?: 'products.csv');
            return;
        }

        $sheet_rows = [array_values(self::HEADERS)];

        foreach ($rows as $row) {
            $sheet_rows[] = [
                (string) ($row[self::COL_NAME] ?? ''),
                (string) ($row[self::COL_EXCERPT] ?? ''),
                (string) ($row[self::COL_CONTENT] ?? ''),
            ];
        }

        $tmp = wp_tempnam($filename);

        if ($tmp === '') {
            wp_die(esc_html__('ساخت فایل اکسل ناموفق بود.', 'wc-product-descriptions-editor'));
        }

        $zip = new ZipArchive();

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            wp_die(esc_html__('باز کردن آرشیو اکسل ناموفق بود.', 'wc-product-descriptions-editor'));
        }

        $sheet_xml = self::build_sheet_xml($sheet_rows);

        $zip->addFromString('[Content_Types].xml', self::content_types_xml());
        $zip->addFromString('_rels/.rels', self::root_rels_xml());
        $zip->addFromString('xl/workbook.xml', self::workbook_xml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbook_rels_xml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
        $zip->close();

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * @param array<int, array{product_name: string, short_description: string, main_description: string}> $rows
     */
    public static function download_csv(array $rows, string $filename): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            wp_die(esc_html__('خروجی CSV ناموفق بود.', 'wc-product-descriptions-editor'));
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values(self::HEADERS));

        foreach ($rows as $row) {
            fputcsv(
                $out,
                [
                    (string) ($row[self::COL_NAME] ?? ''),
                    (string) ($row[self::COL_EXCERPT] ?? ''),
                    (string) ($row[self::COL_CONTENT] ?? ''),
                ]
            );
        }

        fclose($out);
        exit;
    }

    /**
     * @return array<int, array{product_name: string, short_description: string, main_description: string}>
     */
    public static function parse_upload(string $file_path, string $original_name): array
    {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return self::parse_csv($file_path);
        }

        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            return self::parse_xlsx($file_path);
        }

        throw new RuntimeException(__('فرمت فایل پشتیبانی نمی‌شود. xlsx یا csv بارگذاری کنید.', 'wc-product-descriptions-editor'));
    }

    /**
     * @return array<int, array{product_name: string, short_description: string, main_description: string}>
     */
    private static function parse_csv(string $file_path): array
    {
        $handle = fopen($file_path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('خواندن فایل CSV ناموفق بود.', 'wc-product-descriptions-editor'));
        }

        $rows = [];
        $header_map = null;

        while (($data = fgetcsv($handle)) !== false) {

            if ($data === [null] || $data === []) {
                continue;
            }

            $data = array_map(
                static fn($cell): string => trim((string) $cell),
                $data
            );

            if ($header_map === null) {
                $header_map = self::map_headers($data);

                if ($header_map !== null) {
                    continue;
                }

                $header_map = [0, 1, 2];
            }

            $mapped = self::row_from_columns($data, $header_map);

            if ($mapped[self::COL_NAME] !== '') {
                $rows[] = $mapped;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array{product_name: string, short_description: string, main_description: string}>
     */
    private static function parse_xlsx(string $file_path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(__('ZipArchive برای خواندن xlsx در سرور فعال نیست.', 'wc-product-descriptions-editor'));
        }

        $zip = new ZipArchive();

        if ($zip->open($file_path) !== true) {
            throw new RuntimeException(__('باز کردن فایل xlsx ناموفق بود.', 'wc-product-descriptions-editor'));
        }

        $shared = [];
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');

        if (is_string($shared_xml) && $shared_xml !== '') {
            $shared = self::parse_shared_strings($shared_xml);
        }

        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!is_string($sheet_xml) || $sheet_xml === '') {
            throw new RuntimeException(__('شیت اول اکسل یافت نشد.', 'wc-product-descriptions-editor'));
        }

        $matrix = self::parse_sheet_matrix($sheet_xml, $shared);
        $rows   = [];
        $header_map = null;

        foreach ($matrix as $index => $data) {
            if ($header_map === null) {
                $header_map = self::map_headers($data);

                if ($header_map !== null) {
                    continue;
                }

                $header_map = [0, 1, 2];
            }

            $mapped = self::row_from_columns($data, $header_map);

            if ($mapped[self::COL_NAME] !== '') {
                $rows[] = $mapped;
            }
        }

        unset($index);

        return $rows;
    }

    /**
     * @param string[] $header_cells
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function map_headers(array $header_cells): ?array
    {
        $normalized = array_map([self::class, 'normalize_header'], $header_cells);
        $lookup     = array_flip($normalized);

        $name_idx = $lookup[self::normalize_header(self::HEADERS[self::COL_NAME])] ?? null;
        $excerpt_idx = $lookup[self::normalize_header(self::HEADERS[self::COL_EXCERPT])] ?? null;
        $content_idx = $lookup[self::normalize_header(self::HEADERS[self::COL_CONTENT])] ?? null;

        if ($name_idx === null) {
            return null;
        }

        return [
            (int) $name_idx,
            (int) ($excerpt_idx ?? 1),
            (int) ($content_idx ?? 2),
        ];
    }

    /**
     * @param string[] $cells
     * @param array{0: int, 1: int, 2: int} $map
     * @return array{product_name: string, short_description: string, main_description: string}
     */
    private static function row_from_columns(array $cells, array $map): array
    {
        return [
            self::COL_NAME    => trim((string) ($cells[$map[0]] ?? '')),
            self::COL_EXCERPT => (string) ($cells[$map[1]] ?? ''),
            self::COL_CONTENT => (string) ($cells[$map[2]] ?? ''),
        ];
    }

    private static function normalize_header(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private static function build_sheet_xml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        foreach ($rows as $r_index => $row) {
            $row_number = $r_index + 1;
            $xml       .= '<row r="' . $row_number . '">';

            foreach ($row as $c_index => $value) {
                $col_letter = chr(ord('A') + $c_index);
                $cell_ref   = $col_letter . $row_number;
                $xml       .= '<c r="' . esc_attr($cell_ref) . '" t="inlineStr"><is><t>'
                    . self::xml_escape((string) $value)
                    . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @return string[]
     */
    private static function parse_shared_strings(string $xml): array
    {
        $doc = new DOMDocument();

        if (@$doc->loadXML($xml) === false) {
            return [];
        }

        $strings = [];
        $items   = $doc->getElementsByTagName('si');

        foreach ($items as $item) {
            $text = '';

            foreach ($item->getElementsByTagName('t') as $t) {
                $text .= $t->textContent;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param string[] $shared
     * @return array<int, array<int, string>>
     */
    private static function parse_sheet_matrix(string $xml, array $shared): array
    {
        $doc = new DOMDocument();

        if (@$doc->loadXML($xml) === false) {
            return [];
        }

        $matrix = [];
        $rows   = $doc->getElementsByTagName('row');

        foreach ($rows as $row_node) {
            $row_index = max(1, (int) $row_node->getAttribute('r')) - 1;
            $matrix[$row_index] = $matrix[$row_index] ?? [];

            foreach ($row_node->getElementsByTagName('c') as $cell) {
                $ref = (string) $cell->getAttribute('r');
                $col = self::column_index_from_ref($ref);
                $matrix[$row_index][$col] = self::cell_value($cell, $shared);
            }
        }

        ksort($matrix);

        $normalized = [];

        foreach ($matrix as $row) {
            ksort($row);
            $max = $row === [] ? 2 : max(array_keys($row));
            $line = [];

            for ($i = 0; $i <= $max; ++$i) {
                $line[$i] = (string) ($row[$i] ?? '');
            }

            $normalized[] = $line;
        }

        return $normalized;
    }

    private static function column_index_from_ref(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }

        $letters = $m[1];
        $index   = 0;
        $len     = strlen($letters);

        for ($i = 0; $i < $len; ++$i) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return max(0, $index - 1);
    }

    /**
     * @param string[] $shared
     */
    private static function cell_value(DOMElement $cell, array $shared): string
    {
        $type = (string) $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            foreach ($cell->getElementsByTagName('t') as $t) {
                return (string) $t->textContent;
            }

            return '';
        }

        $value_node = $cell->getElementsByTagName('v')->item(0);
        $raw        = $value_node ? (string) $value_node->textContent : '';

        if ($type === 's' && $raw !== '' && isset($shared[(int) $raw])) {
            return (string) $shared[(int) $raw];
        }

        return $raw;
    }

    private static function xml_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function content_types_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function root_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbook_rels_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }
}
