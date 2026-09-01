<?php
/**
 * Metis / OpenAI-compatible API settings.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WCPDE_AI_Settings
{
    private const OPTION_KEY = 'wcpde_ai_settings';

    /**
     * @return array{api_key:string, base_url:string, model:string}
     */
    public static function get(): array
    {
        $defaults = self::defaults();
        $saved    = get_option(self::OPTION_KEY, []);

        if (!is_array($saved)) {
            $saved = [];
        }

        return [
            'api_key'  => self::has_stored_key() ? '********' : '',
            'base_url' => (string) ($saved['base_url'] ?? $defaults['base_url']),
            'model'    => (string) ($saved['model'] ?? $defaults['model']),
        ];
    }

    /**
     * @param array{api_key?:string, base_url?:string, model?:string} $input
     */
    public static function save(array $input): void
    {
        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }

        $defaults = self::defaults();
        $api_key  = trim((string) ($input['api_key'] ?? ''));

        if ($api_key !== '') {
            $current['api_key'] = $api_key;
        }

        $base_url = esc_url_raw(trim((string) ($input['base_url'] ?? '')));
        $current['base_url'] = $base_url !== '' ? untrailingslashit($base_url) : $defaults['base_url'];

        $model = sanitize_text_field(trim((string) ($input['model'] ?? '')));
        $current['model'] = $model !== '' ? $model : $defaults['model'];

        update_option(self::OPTION_KEY, $current, false);
    }

    public static function clear_api_key(): void
    {
        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }

        unset($current['api_key']);
        update_option(self::OPTION_KEY, $current, false);
    }

    public static function is_configured(): bool
    {
        return self::get_api_key() !== '';
    }

    public static function get_api_key(): string
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            return '';
        }

        return trim((string) ($saved['api_key'] ?? ''));
    }

    /**
     * @return array{base_url:string, model:string}
     */
    public static function get_runtime(): array
    {
        $defaults = self::defaults();
        $saved    = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return [
            'base_url' => (string) ($saved['base_url'] ?? $defaults['base_url']),
            'model'    => (string) ($saved['model'] ?? $defaults['model']),
        ];
    }

    /**
     * @return array{api_key:string, base_url:string, model:string}
     */
    private static function defaults(): array
    {
        return [
            'api_key'  => '',
            'base_url' => 'https://api.metisai.ir/openai/v1',
            'model'    => 'gpt-4o-mini',
        ];
    }

    private static function has_stored_key(): bool
    {
        return self::get_api_key() !== '';
    }
}
