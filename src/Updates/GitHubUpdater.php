<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Updates;

use OxyAI\Oxygen\Settings\SettingsRepository;

/**
 * Teaches WordPress to update this plugin from GitHub releases.
 *
 * The plugin is distributed as a release asset (oxyai-X.Y.Z.zip) on GitHub.
 * This class:
 *   - injects an available update into the core plugin-update transient when a
 *     newer release exists (so the Plugins screen shows "update now" and the
 *     auto-update toggle),
 *   - serves the "View details" modal from the release notes,
 *   - normalises the extracted folder name on install,
 *   - preserves the site's existing auto-update choice,
 *   - supports optional token-authenticated checks/downloads for private repos.
 *
 * Self-contained on purpose: no Composer dependency, loads via the plugin's
 * own autoloader, and is bundled by the existing release build.
 */
final class GitHubUpdater
{
    private const POSITIVE_CACHE_TTL = 21600;
    private const NEGATIVE_CACHE_TTL = 300;

    private string $basename;
    private string $slug;
    private string $transientKey = 'oxyai_oxygen_github_release';

    /** Last verification outcome, surfaced to the admin UI. One of: verified|unverified|failed|''. */
    private string $lastVerificationStatus = '';

    /**
     * @param string $pluginFile  Absolute path to the main plugin file.
     * @param string $gitHubRepo  "owner/repo".
     * @param string $version     Currently installed version.
     * @param string $assetPattern Regex selecting the release asset to install.
     * @param SettingsRepository|null $settings Optional source for the GitHub
     *        token and auto-update preference when no constant/filter is set.
     */
    public function __construct(
        private string $pluginFile,
        private string $gitHubRepo,
        private string $version,
        private string $assetPattern = '/\.zip$/i',
        private ?SettingsRepository $settings = null
    ) {
        $this->basename = function_exists('plugin_basename')
            ? plugin_basename($pluginFile)
            : basename(dirname($pluginFile)) . '/' . basename($pluginFile);

        $slug = dirname($this->basename);
        $this->slug = ($slug === '.' || $slug === '' || $slug === DIRECTORY_SEPARATOR)
            ? basename($pluginFile, '.php')
            : $slug;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'verifyDownload'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fixSourceDir'], 10, 4);
        add_filter('auto_update_plugin', [$this, 'enableAutoUpdate'], 10, 2);
        add_filter('http_request_args', [$this, 'authorizePackageDownloads'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'clearCacheAfterUpdate'], 10, 2);
    }

    // ------------------------------------------------------------------
    // WordPress hooks
    // ------------------------------------------------------------------

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function injectUpdate($transient)
    {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $release = $this->fetchRelease();
        if ($release === null) {
            return $transient;
        }

        $package = $this->selectPackageUrl($release);
        if ($package === null) {
            return $transient;
        }

        if (!is_array($transient->response ?? null)) {
            $transient->response = [];
        }
        if (!is_array($transient->no_update ?? null)) {
            $transient->no_update = [];
        }

        $item = $this->buildUpdateObject($release, $package);

        if ($this->isNewer($release)) {
            $transient->response[$this->basename] = $item;
            unset($transient->no_update[$this->basename]);
        } else {
            // Advertise "no update" so the auto-update toggle still renders.
            $item->new_version = $this->version;
            $transient->no_update[$this->basename] = $item;
            unset($transient->response[$this->basename]);
        }

        return $transient;
    }

    /**
     * @param mixed $result
     * @param string $action
     * @param mixed $args
     * @return mixed
     */
    public function pluginInfo($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!is_object($args) || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $release = $this->fetchRelease();
        if ($release === null) {
            return $result;
        }

        $headers = $this->pluginHeaders();
        $package = $this->selectPackageUrl($release);

        $info = new \stdClass();
        $info->name = $headers['Name'] !== '' ? $headers['Name'] : 'OxyAI Oxygen';
        $info->slug = $this->slug;
        $info->version = $this->remoteVersion($release);
        $info->author = $headers['Author'];
        $info->homepage = $headers['PluginURI'] !== '' ? $headers['PluginURI'] : ('https://github.com/' . $this->gitHubRepo);
        $info->requires = $headers['RequiresWP'];
        $info->requires_php = $headers['RequiresPHP'];
        $info->last_updated = (string) ($release['published_at'] ?? '');
        $info->sections = ['changelog' => $this->renderChangelog($release)];
        if ($package !== null) {
            $info->download_link = $package;
        }

        return $info;
    }

    /**
     * Normalise the extracted folder to the plugin slug (matters for the
     * GitHub source zipball fallback, whose folder is owner-repo-<sha>).
     *
     * @param mixed $source
     * @param mixed $remoteSource
     * @param mixed $upgrader
     * @param array<string, mixed> $hookExtra
     * @return mixed
     */
    public function fixSourceDir($source, $remoteSource, $upgrader = null, $hookExtra = [])
    {
        if (!is_string($source) || !is_array($hookExtra) || ($hookExtra['plugin'] ?? '') !== $this->basename) {
            return $source;
        }

        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            return $source;
        }

        $desired = trailingslashit(dirname($source)) . $this->slug;
        if (untrailingslashit($source) === $desired) {
            return $source;
        }

        if ($wp_filesystem->move(untrailingslashit($source), $desired) === true) {
            return trailingslashit($desired);
        }

        return $source;
    }

    /**
     * @param mixed $update
     * @param mixed $item
     * @return mixed
     */
    public function enableAutoUpdate($update, $item)
    {
        if (!is_object($item) || ($item->plugin ?? '') !== $this->basename) {
            return $update;
        }
        if (defined('OXYAI_OXYGEN_DISABLE_AUTO_UPDATES') && OXYAI_OXYGEN_DISABLE_AUTO_UPDATES) {
            return false;
        }

        // Constants/filters keep precedence; the stored preference is the
        // baseline default the filter receives.
        $default = $update;
        if ($this->settings !== null) {
            $default = (bool) $this->settings->get('auto_update_enabled', false);
        }

        if (!function_exists('apply_filters')) {
            return $default;
        }

        return (bool) apply_filters('oxyai_oxygen_enable_auto_updates', $default, $item, $this);
    }

    /**
     * Download our release package ourselves so we can verify its sha256
     * against the GitHub asset digest before WordPress installs it.
     *
     * Returning a local file path short-circuits WordPress's own download.
     * Returning false (the default) lets WordPress download normally — we do
     * that for any package that is not ours.
     *
     * @param mixed $reply
     * @param mixed $package
     * @param mixed $upgrader
     * @return mixed false to continue, string local path, or WP_Error on mismatch
     */
    public function verifyDownload($reply, $package, $upgrader = null)
    {
        if (!is_string($package) || $package === '' || !$this->isGitHubPackageUrl($package)) {
            return $reply;
        }

        $expected = $this->expectedDigestForPackage($package);
        if ($expected === '') {
            // Nothing to verify against — let WordPress download as usual but
            // remember that this release shipped without a checksum.
            $this->lastVerificationStatus = 'unverified';
            $this->rememberVerificationStatus('unverified');
            return $reply;
        }

        if (!function_exists('download_url')) {
            $fileApi = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/file.php' : '';
            if ($fileApi !== '' && is_readable($fileApi)) {
                require_once $fileApi;
            }
        }
        if (!function_exists('download_url')) {
            return $reply;
        }

        $downloaded = download_url($package);
        if (is_wp_error($downloaded)) {
            return $downloaded;
        }

        $verdict = $this->verifyChecksum((string) $downloaded, $expected);
        if ($verdict !== true) {
            if (function_exists('wp_delete_file')) {
                wp_delete_file((string) $downloaded);
            } elseif (is_file((string) $downloaded)) {
                @unlink((string) $downloaded);
            }
            $this->lastVerificationStatus = 'failed';
            $this->rememberVerificationStatus('failed');

            $message = function_exists('__')
                ? __('OxyAI Oxygen update aborted: the downloaded package failed sha256 verification.', 'oxyai-oxygen')
                : 'OxyAI Oxygen update aborted: the downloaded package failed sha256 verification.';
            return new \WP_Error('oxyai_oxygen_checksum_mismatch', $message);
        }

        $this->lastVerificationStatus = 'verified';
        $this->rememberVerificationStatus('verified');

        return $downloaded;
    }

    /**
     * Compare a file's sha256 against the expected hex digest.
     * Pure helper (no WordPress calls) so it is unit testable.
     */
    public function verifyChecksum(string $file, string $expectedSha256): bool
    {
        $expected = strtolower(trim($expectedSha256));
        // Accept the GitHub "sha256:abcdef…" form as well as a bare hex digest.
        if (str_starts_with($expected, 'sha256:')) {
            $expected = substr($expected, 7);
        }
        if ($expected === '' || !is_file($file)) {
            return false;
        }

        $actual = hash_file('sha256', $file);
        return is_string($actual) && hash_equals($expected, strtolower($actual));
    }

    /**
     * Resolve the expected sha256 for a package URL from the cached release:
     * prefer the asset's GitHub `digest`, else a sidecar `<asset>.sha256`.
     */
    private function expectedDigestForPackage(string $package): string
    {
        $release = $this->fetchRelease();
        if ($release === null) {
            return '';
        }

        return $this->digestForPackage($release, $package);
    }

    /**
     * Pure resolver: given a release payload and a chosen package URL, find the
     * matching asset's `digest`, or a sidecar `<asset>.sha256` asset's contents.
     *
     * @param array<string, mixed> $release
     */
    public function digestForPackage(array $release, string $package): string
    {
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];

        $matchedName = '';
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $url = (string) ($asset['url'] ?? '');
            $download = (string) ($asset['browser_download_url'] ?? '');
            if ($package === $url || $package === $download) {
                $matchedName = (string) ($asset['name'] ?? '');
                $digest = trim((string) ($asset['digest'] ?? ''));
                if ($digest !== '') {
                    return $digest;
                }
            }
        }

        if ($matchedName === '') {
            return '';
        }

        // No inline digest — look for a sidecar "<asset>.sha256".
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if ((string) ($asset['name'] ?? '') !== $matchedName . '.sha256') {
                continue;
            }
            $sidecarUrl = (string) ($asset['browser_download_url'] ?? $asset['url'] ?? '');
            return $sidecarUrl === '' ? '' : $this->fetchSidecarDigest($sidecarUrl);
        }

        return '';
    }

    private function fetchSidecarDigest(string $url): string
    {
        if (!function_exists('wp_remote_get')) {
            return '';
        }
        $args = ['timeout' => 10, 'headers' => ['User-Agent' => 'OxyAI-Oxygen-Updater']];
        $token = $this->gitHubToken();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        // A .sha256 file is typically "<hex>  <filename>"; take the first token.
        $body = trim((string) wp_remote_retrieve_body($response));
        $first = preg_split('/\s+/', $body)[0] ?? '';
        return is_string($first) ? $first : '';
    }

    private function rememberVerificationStatus(string $status): void
    {
        if (function_exists('set_site_transient')) {
            set_site_transient($this->transientKey . '_verify', $status, self::POSITIVE_CACHE_TTL);
        }
    }

    /**
     * @param mixed $args
     * @param mixed $url
     * @return mixed
     */
    public function authorizePackageDownloads($args, $url)
    {
        if (!is_array($args) || !is_string($url) || $url === '') {
            return $args;
        }

        $token = $this->gitHubToken();
        if ($token === '' || !$this->isGitHubPackageUrl($url)) {
            return $args;
        }

        $headers = $args['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }

        $headers['Authorization'] = 'Bearer ' . $token;
        $headers['User-Agent'] = $headers['User-Agent'] ?? 'OxyAI-Oxygen-Updater';
        if ($this->isGitHubApiUrl($url)) {
            $headers['Accept'] = 'application/octet-stream';
        }

        $args['headers'] = $headers;

        return $args;
    }

    /**
     * @param mixed $upgrader
     * @param array<string, mixed> $hookExtra
     */
    public function clearCacheAfterUpdate($upgrader, $hookExtra): void
    {
        if (!is_array($hookExtra)) {
            return;
        }
        $isPluginUpdate = ($hookExtra['type'] ?? '') === 'plugin' && ($hookExtra['action'] ?? '') === 'update';
        $touchedUs = ($hookExtra['plugin'] ?? '') === $this->basename
            || in_array($this->basename, (array) ($hookExtra['plugins'] ?? []), true);
        if ($isPluginUpdate && $touchedUs) {
            delete_site_transient($this->transientKey);
        }
    }

    // ------------------------------------------------------------------
    // Release fetching (cached)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRelease(): ?array
    {
        $cached = get_site_transient($this->transientKey);
        if (is_array($cached)) {
            return isset($cached['__none']) ? null : $cached;
        }

        $release = $this->requestLatestRelease();
        set_site_transient(
            $this->transientKey,
            $release ?? ['__none' => true],
            $release === null ? self::NEGATIVE_CACHE_TTL : self::POSITIVE_CACHE_TTL
        );

        return $release;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestLatestRelease(): ?array
    {
        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'OxyAI-Oxygen-Updater',
            ],
        ];
        $token = $this->gitHubToken();
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $this->gitHubRepo . '/releases/latest',
            $args
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------
    // Admin UI surface
    // ------------------------------------------------------------------

    public function installedVersion(): string
    {
        return $this->version;
    }

    /**
     * Clear caches and re-check GitHub, returning the fresh status array.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        if (function_exists('delete_site_transient')) {
            delete_site_transient($this->transientKey);
            delete_site_transient($this->transientKey . '_verify');
        }

        return $this->status();
    }

    /**
     * Snapshot of the update state for the Updates tab. Uses the cached
     * release check (does not force a network call).
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $release = $this->fetchRelease();
        $verification = function_exists('get_site_transient')
            ? get_site_transient($this->transientKey . '_verify')
            : false;

        if ($release === null) {
            return [
                'installed' => $this->version,
                'latest' => '',
                'update_available' => false,
                'changelog' => '',
                'release_url' => 'https://github.com/' . $this->gitHubRepo . '/releases',
                'published_at' => '',
                'verification' => is_string($verification) ? $verification : 'unknown',
                'checked' => false,
            ];
        }

        $package = $this->selectPackageUrl($release);
        $digest = $package !== null ? $this->digestForPackage($release, $package) : '';

        return [
            'installed' => $this->version,
            'latest' => $this->remoteVersion($release),
            'update_available' => $this->isNewer($release),
            'changelog' => $this->renderChangelog($release),
            'release_url' => (string) ($release['html_url'] ?? ('https://github.com/' . $this->gitHubRepo)),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'verification' => is_string($verification) && $verification !== ''
                ? $verification
                : ($digest !== '' ? 'verifiable' : 'unverified'),
            'checked' => true,
        ];
    }

    private function gitHubToken(): string
    {
        // Precedence: constant > stored setting (then the filter may override).
        $token = defined('OXYAI_OXYGEN_GITHUB_TOKEN') ? (string) OXYAI_OXYGEN_GITHUB_TOKEN : '';
        if ($token === '' && $this->settings !== null) {
            $token = $this->settings->getSecret('github_token');
        }
        if (!function_exists('apply_filters')) {
            return $token;
        }

        return (string) apply_filters('oxyai_oxygen_github_token', $token);
    }

    // ------------------------------------------------------------------
    // Pure helpers (no WordPress calls) - unit testable
    // ------------------------------------------------------------------

    public function normalizeVersion(string $tag): string
    {
        return ltrim(trim($tag), 'vV');
    }

    /**
     * @param array<string, mixed> $release
     */
    public function remoteVersion(array $release): string
    {
        return $this->normalizeVersion((string) ($release['tag_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $release
     */
    public function isNewer(array $release): bool
    {
        $remote = $this->remoteVersion($release);
        return $remote !== '' && version_compare($remote, $this->version, '>');
    }

    /**
     * Prefer a release asset matching the pattern; fall back to the source
     * zipball (folder normalised by fixSourceDir).
     *
     * @param array<string, mixed> $release
     */
    public function selectPackageUrl(array $release): ?string
    {
        $preferAuthenticatedApiUrl = $this->gitHubToken() !== '';

        foreach (($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url = $preferAuthenticatedApiUrl
                ? (string) ($asset['url'] ?? $asset['browser_download_url'] ?? '')
                : (string) ($asset['browser_download_url'] ?? '');
            if ($name !== '' && $url !== '' && preg_match($this->assetPattern, $name) === 1) {
                return $url;
            }
        }

        $zipball = $release['zipball_url'] ?? null;
        return is_string($zipball) && $zipball !== '' ? $zipball : null;
    }

    /**
     * @param array<string, mixed> $release
     */
    public function buildUpdateObject(array $release, string $package): \stdClass
    {
        $headers = $this->pluginHeaders();

        $item = new \stdClass();
        $item->slug = $this->slug;
        $item->plugin = $this->basename;
        $item->new_version = $this->remoteVersion($release);
        $item->url = (string) ($release['html_url'] ?? ('https://github.com/' . $this->gitHubRepo));
        $item->package = $package;
        $item->requires = $headers['RequiresWP'];
        $item->tested = '';
        $item->requires_php = $headers['RequiresPHP'];

        return $item;
    }

    /**
     * @return array<string, string>
     */
    private function pluginHeaders(): array
    {
        $defaults = ['Name' => '', 'Author' => '', 'PluginURI' => '', 'RequiresWP' => '', 'RequiresPHP' => ''];
        if (!function_exists('get_file_data')) {
            $pluginApi = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
            if ($pluginApi !== '' && is_readable($pluginApi)) {
                require_once $pluginApi;
            }
            if (!function_exists('get_file_data')) {
                return $defaults;
            }
        }

        $data = get_file_data($this->pluginFile, [
            'Name' => 'Plugin Name',
            'Author' => 'Author',
            'PluginURI' => 'Plugin URI',
            'RequiresWP' => 'Requires at least',
            'RequiresPHP' => 'Requires PHP',
        ]);

        return array_merge($defaults, array_map(static fn ($v): string => (string) $v, $data));
    }

    private function isGitHubApiUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string) ($parts['host'] ?? '')) === 'api.github.com';
    }

    private function isGitHubPackageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === 'api.github.com') {
            return str_starts_with($path, '/repos/' . $this->gitHubRepo . '/releases/assets/')
                || str_starts_with($path, '/repos/' . $this->gitHubRepo . '/zipball/');
        }

        return $host === 'github.com'
            && str_starts_with($path, '/' . $this->gitHubRepo . '/releases/download/');
    }

    /**
     * @param array<string, mixed> $release
     */
    private function renderChangelog(array $release): string
    {
        $body = trim((string) ($release['body'] ?? ''));
        if ($body === '') {
            $body = 'See the release on GitHub: ' . (string) ($release['html_url'] ?? '');
        }

        $html = function_exists('wpautop') ? wpautop($body) : nl2br($body);
        return function_exists('wp_kses_post') ? wp_kses_post($html) : $html;
    }
}
