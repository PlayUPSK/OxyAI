<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Security;

use WP_Error;

/**
 * Transient-based sliding-window rate limiter for live (non-dryRun) MCP writes.
 *
 * Self-contained: stores a list of recent write timestamps in a single
 * transient and prunes entries outside the window on each check. Defaults to
 * 60 writes / 5 minutes, both filterable via
 * `oxyai_oxygen_mcp_write_rate_limit` => ['max' => int, 'window' => seconds].
 */
final class RateLimiter
{
    private const TRANSIENT_KEY = 'oxyai_oxygen_mcp_write_rl';

    private int $max;
    private int $window;

    public function __construct(int $max = 60, int $window = 300)
    {
        $config = ['max' => $max, 'window' => $window];
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('oxyai_oxygen_mcp_write_rate_limit', $config);
            if (is_array($filtered)) {
                $config = array_merge($config, $filtered);
            }
        }

        $this->max = max(0, (int) ($config['max'] ?? $max));
        $this->window = max(1, (int) ($config['window'] ?? $window));
    }

    /**
     * Record one live write and return a 429-style WP_Error if the window is
     * exhausted, or null when the write is allowed.
     *
     * @param string $bucket optional per-user/per-token bucket suffix
     */
    public function hit(string $bucket = 'global'): ?WP_Error
    {
        if ($this->max === 0) {
            // 0 disables the feature entirely.
            return null;
        }

        $key = self::TRANSIENT_KEY . '_' . md5($bucket);
        $now = time();
        $cutoff = $now - $this->window;

        $stored = function_exists('get_transient') ? get_transient($key) : false;
        $timestamps = is_array($stored) ? array_values(array_filter($stored, static fn ($t): bool => is_int($t) && $t > $cutoff)) : [];

        if (count($timestamps) >= $this->max) {
            $oldest = $timestamps[0] ?? $now;
            $retryAfter = max(1, ($oldest + $this->window) - $now);

            return new WP_Error('oxyai_oxygen_rate_limited', sprintf(
                /* translators: 1: max 2: window seconds 3: retry seconds */
                __('Write rate limit reached (%1$d writes per %2$d seconds). Retry in about %3$d seconds, or batch edits into a single apply_oxygen_operations call to use fewer writes.', 'oxyai-oxygen'),
                $this->max,
                $this->window,
                $retryAfter
            ), [
                'status' => 429,
                'retryAfter' => $retryAfter,
                'limit' => $this->max,
                'windowSeconds' => $this->window,
            ]);
        }

        $timestamps[] = $now;
        if (function_exists('set_transient')) {
            set_transient($key, $timestamps, $this->window);
        }

        return null;
    }

    public function max(): int
    {
        return $this->max;
    }

    public function window(): int
    {
        return $this->window;
    }
}
