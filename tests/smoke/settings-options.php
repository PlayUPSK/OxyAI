<?php

declare(strict_types=1);

use OxyAI\Oxygen\Settings\SettingsRepository;

if (!defined('OXYAI_OXYGEN_OPTION')) {
    define('OXYAI_OXYGEN_OPTION', 'oxyai_oxygen_settings');
}

$GLOBALS['oxyaiSmokeOptions'] = [];

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['oxyaiSmokeOptions'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['oxyaiSmokeOptions'][$name] = $value;
        return true;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
    }
}

require_once __DIR__ . '/../../src/Settings/SettingsRepository.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$repo = new SettingsRepository();

// ---- defaults include the new update options ----
$defaults = $repo->defaults();
$check(array_key_exists('github_token', $defaults), 'defaults expose github_token');
$check($defaults['github_token'] === '', 'github_token default is empty');
$check($defaults['auto_update_enabled'] === false, 'auto_update_enabled default is false');
$check(in_array('github_token', $repo->secretKeys(), true), 'github_token is registered as a secret key');

// ---- sanitize round-trips a github token (encrypted at rest) ----
$GLOBALS['oxyaiSmokeOptions'] = [];
$sanitized = $repo->sanitize([
    'provider' => 'anthropic',
    'github_token' => 'ghp_secrettoken1234567890',
    'auto_update_enabled' => '1',
]);
$check(str_starts_with((string) $sanitized['github_token'], 'enc:'), 'github_token stored encrypted (enc: prefix)');
$check(strpos((string) $sanitized['github_token'], 'ghp_secrettoken') === false, 'raw github token never stored in plaintext');
$check($sanitized['auto_update_enabled'] === true, 'auto_update_enabled persisted as bool true');

// persist and read back decrypted
$GLOBALS['oxyaiSmokeOptions'][OXYAI_OXYGEN_OPTION] = $sanitized;
$check($repo->getSecret('github_token') === 'ghp_secrettoken1234567890', 'github token decrypts back to original');

// ---- blank github token keeps the current stored value ----
$kept = $repo->sanitize(['provider' => 'anthropic', 'github_token' => '']);
$check($kept['github_token'] === $sanitized['github_token'], 'blank github token keeps the existing encrypted value');

// ---- auto_update_enabled "0" is treated as false ----
$off = $repo->sanitize(['provider' => 'openai', 'auto_update_enabled' => '0']);
$check($off['auto_update_enabled'] === false, '"0" disables auto update');

// ---- masking never reveals the full secret ----
$check($repo->mask('') === '', 'empty secret masks to empty string');
$masked = $repo->mask('ghp_secrettoken1234567890');
$check(str_starts_with($masked, 'ghp_'), 'mask keeps a short visible prefix');
$check(str_ends_with($masked, '7890'), 'mask keeps a short visible suffix');
$check(str_contains($masked, '•'), 'mask includes dot characters');
$check(strpos($masked, 'secrettoken') === false, 'mask hides the middle of the secret');
$check(strpos($repo->mask('short'), 'short') === false, 'short secrets mask entirely to dots');

// ---- set() encrypts secret keys and stores plain values directly ----
$GLOBALS['oxyaiSmokeOptions'] = [];
$repo->set('github_token', 'ghp_anothertoken000111222');
$stored = $GLOBALS['oxyaiSmokeOptions'][OXYAI_OXYGEN_OPTION]['github_token'] ?? '';
$check(str_starts_with((string) $stored, 'enc:'), 'set() encrypts github_token');
$check($repo->getSecret('github_token') === 'ghp_anothertoken000111222', 'set() value round-trips via getSecret');

$repo->set('auto_update_enabled', true);
$check($repo->get('auto_update_enabled') === true, 'set() stores non-secret values verbatim');

// ---- regenerateMcpToken persists and returns a fresh token ----
$token = $repo->regenerateMcpToken();
$check(str_starts_with($token, 'oxyai_'), 'regenerated token has oxyai_ prefix');
$check($repo->get('mcp_token') === $token, 'regenerated token is persisted');

if ($failures > 0) {
    fwrite(STDERR, "settings-options FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "settings-options-ok\n";
