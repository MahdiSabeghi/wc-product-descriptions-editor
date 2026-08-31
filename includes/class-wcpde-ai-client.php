<?php
/**
 * OpenAI-compatible chat client (Metis).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_AI_Client
{
    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @return array{success:bool, content?:string, message?:string}
     */
    public function chat(array $messages, bool $json_object = true): array
    {
        $api_key = WCPDE_AI_Settings::get_api_key();

        if ($api_key === '') {
            return [
                'success' => false,
                'message' => __('کلید API متیس تنظیم نشده است.', 'wc-product-descriptions-editor'),
            ];
        }

        $runtime  = WCPDE_AI_Settings::get_runtime();
        $endpoint = trailingslashit($runtime['base_url']) . 'chat/completions';

        $body = [
            'model'       => $runtime['model'],
            'temperature' => 0.2,
            'messages'    => $messages,
        ];

        if ($json_object) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? '') : '';

            return [
                'success' => false,
                'message' => $message !== '' ? $message : sprintf(__('خطای API (%d)', 'wc-product-descriptions-editor'), $code),
            ];
        }

        if (!is_array($data)) {
            return [
                'success' => false,
                'message' => __('پاسخ API نامعتبر بود.', 'wc-product-descriptions-editor'),
            ];
        }

        $content = (string) ($data['choices'][0]['message']['content'] ?? '');

        if (trim($content) === '') {
            return [
                'success' => false,
                'message' => __('پاسخ AI خالی بود.', 'wc-product-descriptions-editor'),
            ];
        }

        return [
            'success' => true,
            'content' => $content,
        ];
    }
}
