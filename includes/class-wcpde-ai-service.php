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
                    'content' => 'You convert Persian plain text into a product spec table JSON. Use layout "matrix" when a grouping key repeats with different sub-fields (e.g. age + multiple measurements). Otherwise use layout "simple". Never invent data. Output JSON only.',
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
        $matrix  = WCPDE_AI_Table::matrix_from_ai_payload($payload);

        if ($rows === [] && $matrix === null) {
            return [
                'success' => false,
                'message' => __('AI جدول معتبری برنگرداند.', 'wc-product-descriptions-editor'),
            ];
        }

        $html = WCPDE_AI_Table::build_from_payload($payload);

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
            'متن زیر را به جدول مشخصات محصول تبدیل کن و فقط JSON برگردان.',
            '',
            'دو نوع layout:',
            '',
            '1) layout=simple — برای مشخصات تکی (جنس، رنگ، وزن و …):',
            '{"layout":"simple","rows":[{"label":"عنوان","value":"مقدار"}]}',
            '',
            '2) layout=matrix — وقتی یک کلید گروه‌بندی (مثل سن، سایز، مدل) تکرار می‌شود و برای هر گروه چند زیرمقدار داری (مثل عرض پیرهن، عرض کت):',
            '{"layout":"matrix","columns":["عرض پیرهن","عرض کت"],"rows":[{"label":"سن ۵ سال","values":["۱۰ سانت","۱۵ سانت"]},{"label":"سن ۶ سال","values":["۱۵ سانت","۲۰ سانت"]}]}',
            '',
            'قوانین:',
            '- اگر می‌توانی داده را به صورت ماتریس (ردیف × ستون) نمایش دهی، حتماً layout=matrix بده.',
            '- در matrix: label هر ردیف فقط همان کلید گروه (مثلاً «سن ۵ سال») باشد، نه ترکیب با نام ستون.',
            '- columns فقط نام زیرفیلدها باشد (مثلاً عرض پیرهن، عرض کت).',
            '- values در هر ردیف به همان ترتیب columns باشد.',
            '- فقط از اطلاعات داخل متن استفاده کن؛ چیزی اختراع نکن.',
            '- نام محصول، قیمت، موجودی، SKU یا داده‌ای که در متن نیست را اضافه نکن.',
            '- فقط JSON برگردان، بدون توضیح اضافه.',
            '',
            '--- متن توضیحات کوتاه ---',
            mb_substr($source_text, 0, 8000),
        ];

        return implode("\n", $lines);
    }
}
