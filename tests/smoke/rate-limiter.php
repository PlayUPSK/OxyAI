<?php

declare(strict_types=1);

use OxyAI\Oxygen\Security\RateLimiter;

// ---- minimal WP stubs with an in-memory transient store ----
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        public $data;

        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = (string) $code;
            $this->message = (string) $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }
}

$GLOBALS['__transients'] = [];
if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['__transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0): bool
    {
        $GLOBALS['__transients'][$key] = $value;
        return true;
    }
}

require_once __DIR__ . '/../../src/Security/RateLimiter.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

// max=3 within the window: first three pass, fourth is 429.
$limiter = new RateLimiter(3, 300);
$check($limiter->hit('t') === null, 'rate limiter allows write 1');
$check($limiter->hit('t') === null, 'rate limiter allows write 2');
$check($limiter->hit('t') === null, 'rate limiter allows write 3');
$blocked = $limiter->hit('t');
$check($blocked instanceof WP_Error, 'rate limiter blocks write 4');
$check($blocked instanceof WP_Error && ($blocked->get_error_data()['status'] ?? 0) === 429, 'rate limit returns 429 status');
$check($blocked instanceof WP_Error && ($blocked->get_error_data()['retryAfter'] ?? 0) > 0, 'rate limit returns retryAfter');
$check($blocked instanceof WP_Error && str_contains($blocked->get_error_message(), 'apply_oxygen_operations'), 'rate limit message steers toward batching');

// Separate bucket is independent.
$check($limiter->hit('other') === null, 'separate bucket is independent');

// max=0 disables the limiter entirely.
$GLOBALS['__transients'] = [];
$disabled = new RateLimiter(0, 300);
for ($i = 0; $i < 10; $i++) {
    $check($disabled->hit('z') === null, "disabled limiter allows write {$i}");
}

if ($failures > 0) {
    fwrite(STDERR, "rate-limiter FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "rate-limiter-ok\n";
