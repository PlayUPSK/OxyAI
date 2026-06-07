<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Updates;

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

    /**
     * @param string $pluginFile  Absolute path to the main plugin file.
     * @param string $gitHubRepo  "owner/repo".
     * @param string $version     Currently installed version.
     * @param string $assetPattern Regex selecting the release asset to install.
     */
    public function __construct(
        private string $pluginFile,
        private string $gitHubRepo,
        private string $version,
        private string $assetPattern = '/\.zip$/i'
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
        if (!function_exists('apply_filters')) {
            return $update;
        }

        return (bool) apply_filters('oxyai_oxygen_enable_auto_updates', $update, $item, $this);
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

    private function gitHubToken(): string
    {
        $token = defined('OXYAI_OXYGEN_GITHUB_TOKEN') ? (string) OXYAI_OXYGEN_GITHUB_TOKEN : '';
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
