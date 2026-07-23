<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Admin;

/**
 * Live environment requirements for the Connect tab.
 *
 * Each check returns a pass/fail row with a human label and detail string.
 * The pure evaluation logic is split out so it can be unit tested without a
 * full WordPress runtime.
 */
final class RequirementsChecker
{
    private const MIN_PHP = '8.4';

    /**
     * @return list<array{id: string, label: string, pass: bool, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];

        $checks[] = [
            'id' => 'php',
            'label' => sprintf('PHP %s or newer', self::MIN_PHP),
            'pass' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'detail' => PHP_VERSION,
        ];

        [$oxygenActive, $oxygenVersion] = $this->detectOxygen();
        $checks[] = [
            'id' => 'oxygen',
            'label' => 'Oxygen / Breakdance builder detected',
            'pass' => $oxygenActive,
            'detail' => $oxygenActive
                ? ('Detected' . ($oxygenVersion !== '' ? ' (' . $oxygenVersion . ')' : ''))
                : 'Not detected',
        ];

        $isSsl = function_exists('is_ssl') ? is_ssl() : false;
        $checks[] = [
            'id' => 'https',
            'label' => 'Site served over HTTPS',
            'pass' => $isSsl,
            'detail' => $isSsl ? 'Secure' : 'HTTP only — MCP clients may refuse insecure URLs',
        ];

        $pretty = $this->hasPrettyPermalinks();
        $checks[] = [
            'id' => 'permalinks',
            'label' => 'Pretty permalinks enabled',
            'pass' => $pretty,
            'detail' => $pretty ? 'Enabled' : 'Plain permalinks break REST routes',
        ];

        [$reachable, $detail] = $this->probeEndpoint();
        $checks[] = [
            'id' => 'mcp',
            'label' => 'MCP endpoint reachable',
            'pass' => $reachable,
            'detail' => $detail,
        ];

        return $checks;
    }

    public function allPass(): bool
    {
        foreach ($this->checks() as $check) {
            if (!$check['pass']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: bool, 1: string} [active, version]
     */
    private function detectOxygen(): array
    {
        if (
            defined('__BREAKDANCE_PLUGIN_FILE__')
            && defined('BREAKDANCE_MODE')
            && constant('BREAKDANCE_MODE') === 'oxygen'
        ) {
            $version = defined('__BREAKDANCE_VERSION') ? (string) constant('__BREAKDANCE_VERSION') : '';
            return [true, $version];
        }

        if (defined('CT_VERSION')) {
            return [true, (string) constant('CT_VERSION')];
        }

        if (class_exists('\\OxygenElements\\Container')) {
            return [true, ''];
        }

        return [false, ''];
    }

    private function hasPrettyPermalinks(): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        return trim((string) get_option('permalink_structure')) !== '';
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probeEndpoint(): array
    {
        if (!function_exists('rest_url') || !function_exists('wp_remote_get')) {
            return [false, 'Unavailable in this context'];
        }

        $url = rest_url('oxyai/v1/mcp');
        $response = wp_remote_get($url, [
            'timeout' => 5,
            'sslverify' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return [false, 'Request failed'];
        }

        $code = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;

        // The endpoint exists if it answers at all. 401/403/405 still prove the
        // route is registered (auth/method gating), which is what we care about.
        $reachable = in_array($code, [200, 400, 401, 403, 405, 406], true);

        return [$reachable, 'HTTP ' . $code];
    }
}
