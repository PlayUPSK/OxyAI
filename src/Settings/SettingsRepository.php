<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Settings;

final class SettingsRepository
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'provider' => 'openai',
            'openai_api_key' => '',
            'openai_model' => 'gpt-5.2',
            'anthropic_api_key' => '',
            'anthropic_model' => 'claude-opus-4-1-20250805',
            'compatible_api_key' => '',
            'compatible_endpoint' => '',
            'compatible_model' => 'local-model',
            'history_enabled' => false,
            'mcp_token' => '',
            'github_token' => '',
            'auto_update_enabled' => false,
        ];
    }

    /**
     * Keys whose values are encrypted at rest and must never be echoed raw.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return ['openai_api_key', 'anthropic_api_key', 'compatible_api_key', 'github_token'];
    }

    public function register(): void
    {
        register_setting('oxyai_oxygen_settings', OXYAI_OXYGEN_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => $this->defaults(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(OXYAI_OXYGEN_OPTION, []);
        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $current = $this->all();
        $sanitized = $this->defaults();

        $sanitized['provider'] = in_array(($input['provider'] ?? ''), ['openai', 'anthropic', 'compatible'], true)
            ? (string) $input['provider']
            : 'openai';

        foreach (['openai_model', 'anthropic_model', 'compatible_model', 'compatible_endpoint', 'mcp_token'] as $key) {
            $sanitized[$key] = isset($input[$key]) ? sanitize_text_field((string) $input[$key]) : (string) $current[$key];
        }

        foreach ($this->secretKeys() as $key) {
            $value = isset($input[$key]) ? trim((string) $input[$key]) : '';
            $sanitized[$key] = $value === '' ? (string) $current[$key] : $this->encrypt($value);
        }

        $sanitized['history_enabled'] = !empty($input['history_enabled']);
        $sanitized['auto_update_enabled'] = !empty($input['auto_update_enabled']);
        if ($sanitized['mcp_token'] === '') {
            $sanitized['mcp_token'] = $this->generateMcpToken();
        }

        return $sanitized;
    }

    public function getSecret(string $key): string
    {
        $value = (string) $this->get($key, '');
        return $this->decrypt($value);
    }

    public function ensureMcpToken(): string
    {
        $settings = $this->all();
        $token = is_string($settings['mcp_token'] ?? null) ? (string) $settings['mcp_token'] : '';
        if ($token !== '') {
            return $token;
        }

        $token = $this->generateMcpToken();
        $settings['mcp_token'] = $token;
        update_option(OXYAI_OXYGEN_OPTION, $settings, false);

        return $token;
    }

    public function generateMcpToken(): string
    {
        return 'oxyai_' . bin2hex(random_bytes(24));
    }

    /**
     * Persist a single option without disturbing the rest, encrypting the
     * value first when the key is a secret.
     *
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $settings = $this->all();
        $settings[$key] = in_array($key, $this->secretKeys(), true)
            ? $this->encrypt(trim((string) $value))
            : $value;
        update_option(OXYAI_OXYGEN_OPTION, $settings, false);
    }

    /**
     * Regenerate, persist, and return a fresh MCP token.
     */
    public function regenerateMcpToken(): string
    {
        $token = $this->generateMcpToken();
        $this->set('mcp_token', $token);

        return $token;
    }

    /**
     * Produce a masked preview of a secret for display, never the full value.
     * Example: "sk-ab••••wxyz". Short or empty secrets collapse to dots only.
     */
    public function mask(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }

        $length = strlen($secret);
        if ($length <= 8) {
            return str_repeat('•', max($length, 4));
        }

        return substr($secret, 0, 4) . '••••' . substr($secret, -4);
    }

    /**
     * Masked preview of a stored (encrypted) secret option.
     */
    public function maskedSecret(string $key): string
    {
        return $this->mask($this->getSecret($key));
    }

    private function encrypt(string $plain): string
    {
        if (!function_exists('openssl_encrypt')) {
            return 'plain:' . $plain;
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $this->secretKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return 'plain:' . $plain;
        }

        return 'enc:' . base64_encode($iv . $cipher);
    }

    private function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, 'plain:')) {
            return substr($stored, 6);
        }

        if (!str_starts_with($stored, 'enc:') || !function_exists('openssl_decrypt')) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, 4), true);
        if (!is_string($raw) || strlen($raw) <= 16) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $this->secretKey(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    private function secretKey(): string
    {
        $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . home_url();
        return hash('sha256', $salt, true);
    }
}
