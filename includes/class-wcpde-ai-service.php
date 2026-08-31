<?php
/**
 * AI short-description table generation for products.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_AI_Service
{
    /**
     * @return array{success:bool, message?:string, html?:string, rows?:array<int, array{label:string, value:string}>}
     */
    public function generate_short_description_table(int $product_id, string $source_text): array
    {
        if (!WCPDE_AI_Settings::is_configured()) {
            return [
                'success' => false,
                'message' => __('کلید API متیس را در تنظیمات وارد کنید.', 'wc-product-descriptions-editor'),
            ];
        }

        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return [
                'success' => false,
                'message' => __('محصول یافت نشد.', 'wc-product-descriptions-editor'),
            ];
        }

        $source_text = trim(wp_strip_all_tags($source_text));

        if ($source_text === '') {
            return [
                'success' => false,
                'message' => __('ابتدا متن توضیحات کوتاه را وارد کنید.', 'wc-product-descriptions-editor'),
            ];
        }

        $prompt = $this->build_prompt($source_text);
        $client = new WCPDE_AI_Client();
        $result = $client->chat(
            [
                [
                    'role'    => 'system',
                    'content' => 'You convert Persian plain text into a spec table JSON. Output ONLY {"rows":[{"label":"...","value":"..."}]}. Never invent data.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            true
        );

        if (empty($result['success'])) {
            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? __('خطای AI', 'wc-product-descriptions-editor')),
            ];
        }

        $payload = json_decode((string) ($result['content'] ?? ''), true);
        $rows    = WCPDE_AI_Table::rows_from_ai_payload($payload);

        if ($rows === []) {
            return [
                'success' => false,
                'message' => __('AI جدول معتبری برنگرداند.', 'wc-product-descriptions-editor'),
            ];
        }

        $html = WCPDE_AI_Table::build_html($rows);

        if ($html === '') {
            return [
                'success' => false,
                'message' => __('ساخت HTML جدول ناموفق بود.', 'wc-product-descriptions-editor'),
            ];
        }

        return [
            'success' => true,
            'message' => __('جدول از متن توضیحات کوتاه ساخته شد.', 'wc-product-descriptions-editor'),
            'html'    => $html,
            'rows'    => $rows,
        ];
    }

    private function build_prompt(string $source_text): string
    {
        $lines = [
            'متن زیر را فقط و فقط به جدول دو ستونه (عنوان | مقدار) تبدیل کن.',
            'خروجی JSON با ساختار: {"rows":[{"label":"...","value":"..."}]}',
            'قوانین:',
            '- فقط از اطلاعات داخل متن استفاده کن.',
            '- نام محصول، قیمت، موجودی، SKU، دسته‌بندی یا هر داده‌ای که در متن نیست را اضافه نکن.',
            '- اگر متن سایزبندی، لیست یا چند بخش دارد، هر مورد معنادار را یک ردیف جدا بساز.',
            '- label ستون راست (عنوان) و value ستون چپ (مقدار) باشد.',
            '- فقط JSON برگردان، بدون توضیح اضافه.',
            '',
            '--- متن توضیحات کوتاه ---',
            mb_substr($source_text, 0, 8000),
        ];

        return implode("\n", $lines);
    }
}
