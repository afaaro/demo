<?php
declare(strict_types=1);

namespace System\Library;

use Closure;
use ReflectionClass;
use RuntimeException;
use System\Engine\Registry;
use System\Library\Config;
use System\Library\Request;

// =============================================================================
// View — template renderer with theme, layout, partials, and escaping
// =============================================================================

/**
 * View
 *
 * Responsibilities:
 *   - Assign variables and render PHP template files
 *   - Resolve layouts: module-specific → theme → global fallback
 *   - Resolve partials: theme/partials → module/views/partials fallback
 *   - Asset URL generation with cache-busting version stamps
 *   - Safe HTML escaping helpers for templates
 *   - Theme management with validation
 *
 * Usage from a controller:
 *
 *   $this->view->assign('title', 'Hello')
 *              ->assign('posts', $posts)
 *              ->render($this, 'index');   // explicit $this — no backtrace magic
 *
 * Usage in a template:
 *
 *   <?= $view->e($title) ?>
 *   <?= $view->asset('css/app.css') ?>
 *   <?php $view->partial('header') ?>
 */
class View
{
    // -------------------------------------------------------------------------
    // Dependencies
    // -------------------------------------------------------------------------

    protected Registry $registry;
    protected Config   $config;
    protected Request  $request;

    /** @var list<string> */
    private array $styles = [];

    /** @var list<string> */
    private array $scripts_header = [];

    /** @var list<string> */
    private array $scripts_footer = [];

    /** @var list<string> */
    private array $inline_styles = [];

    /** @var list<string> */
    private array $inline_scripts = [];

    /** @var list<string> */
    private array $dependencies = [];

    /** @var list<string> */
    private array $title = [];

    private string $description = '';
    private string $keywords = '';

    /** @var array<string, string> */
    private array $meta = [];

    /** @var array<string, string> */
    private array $og_tags = [];

    private string $title_separator = ' · ';

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** Variables assigned for the current render cycle. */
    protected array $vars = [];

    /** Active theme name. */
    protected string $activeTheme = 'default';

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
        $this->config   = $registry->get('config');
        $this->request  = $registry->get('request');

        $theme = $this->sanitizeThemeName(
            (string) $this->config->get('app.theme', 'default')
        );
        $this->setTheme($theme);
    }

    // =========================================================================
    // MAGIC — registry proxy
    // =========================================================================

    /**
     * Allow templates to access registered services via $view->serviceName.
     *
     * Only proxies keys that are actually registered — unknown keys throw
     * rather than returning null silently.
     *
     * @throws RuntimeException for unregistered keys.
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->vars)) {
            return $this->vars[$key];
        }
        if (!$this->registry->has($key)) {
            throw new RuntimeException("View: '{$key}' is not assigned or registered.");
        }
        return $this->registry->get($key);
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->vars) || $this->registry->has($key);
    }

    // =========================================================================
    // THEME MANAGEMENT
    // =========================================================================

    /**
     * Switch the active theme. Validates that the theme directory exists.
     *
     * @throws RuntimeException if the theme directory is not found.
     */
    public function setTheme(string $theme): void
    {
        $theme = $this->sanitizeThemeName($theme);

        if (!$this->themeExists($theme)) {
            throw new RuntimeException("Theme '{$theme}' not found at: " . $this->themeBasePath($theme));
        }

        $this->activeTheme = $theme;
    }

    public function getTheme(): string
    {
        return $this->activeTheme;
    }

    public function themeExists(string $theme): bool
    {
        return is_dir($this->themeBasePath($this->sanitizeThemeName($theme)));
    }

    /**
     * Absolute path to a file inside the active theme directory.
     *
     *   $view->themePath('partials/header.php')
     *   → /var/www/themes/default/partials/header.php
     */
    public function themePath(string $path = ''): string
    {
        return $this->themeBasePath($this->activeTheme)
            . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    // =========================================================================
    // ASSET URLs
    // =========================================================================

    /**
     * Return a cache-busted URL for a theme asset.
     *
     *   $view->asset('css/app.css')
     *   → https://example.com/themes/default/assets/css/app.css?v=1710000000
     *
     * Version is the file's mtime. If the file does not exist, '0' is used
     * rather than time() so that missing assets get a stable (debuggable) URL.
     *
     * @throws RuntimeException on path traversal attempts.
     */
    public function asset(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException("Invalid asset path: '{$path}'.");
        }

        // Path and URL both use 'assets/' (plural) — they used to disagree.
        $fullPath = $this->themePath('asset/' . $path);
        $version  = is_file($fullPath) ? (string) filemtime($fullPath) : '0';
        $base     = rtrim($this->request->baseUrl(), '/');

        return $base . '/themes/' . $this->activeTheme . '/asset/' . $path . '?v=' . $version;
    }

    // =========================================================================
    // VARIABLE ASSIGNMENT
    // =========================================================================

    /**
     * Assign one variable or an array of variables for the next render.
     * Returns $this so calls can be chained.
     *
     *   $view->assign('title', 'Hello')->assign(['a' => 1, 'b' => 2]);
     */
    public function assign(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->vars[(string) $k] = $v;
                $this->syncAssignedTitle((string) $k, $v);
            }
        } else {
            $this->vars[$key] = $value;
            $this->syncAssignedTitle($key, $value);
        }

        return $this;
    }

    private function syncAssignedTitle(string $key, mixed $value): void
    {
        if ($key !== 'title' || !is_string($value)) {
            return;
        }
        $value = trim($value);
        if ($value !== '') {
            $this->setTitle($value);
        }
    }

    /**
     * Return all currently assigned variables.
     */
    public function getVars(): array
    {
        return $this->vars;
    }

    /**
     * Clear all assigned variables (useful between renders in tests).
     */
    public function clearVars(): static
    {
        $this->vars = [];
        return $this;
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * Render the view file that corresponds to the calling controller + action,
     * then wrap it in a layout.
     *
     * The controller passes itself explicitly — no debug_backtrace() magic.
     *
     *   // In a controller:
     *   return $this->view->render($this, 'index');
     *   return $this->view->render($this, 'index', 'main');      // explicit layout
     *   return $this->view->render($this, 'index', '');          // no layout
     *   return $this->view->render($this, 'index', [], ['id'=>1]); // extra data
     *
     * @param  object $controller  The controller instance (pass $this).
     * @param  string $action      View file name without extension (e.g. 'index').
     * @param  string $layout      Layout name, or '' to skip layout wrapping.
     * @param  array  $data        Extra variables merged on top of assign()ed ones.
     */
    public function render(
        object $controller,
        string $action  = 'index',
        string $layout  = 'main',
        array  $data    = [],
    ): string {
        $data = array_merge($this->vars, $data, ['view' => $this]);
        $this->syncAssignedTitle('title', $data['title'] ?? null);

        $ctrlFile   = (new ReflectionClass($controller))->getFileName();
        $moduleRoot = $this->moduleRootFromFile($ctrlFile);
        $viewFile   = $this->findViewFile($ctrlFile, $action);
        $content    = $this->renderFile($viewFile, $data);

        $layout = (defined('ADMIN_PANEL') && ADMIN_PANEL) ? 'admin' : $layout;
        
        if ($layout === '') {
            return $content;
        }
 
        $layoutFile = $this->findLayout($moduleRoot, $layout);
        return $this->renderFile($layoutFile, array_merge($data, ['content' => $content]));
    }

    /**
     * Render an absolute or theme-relative template path directly.
     * Useful for error pages, emails, and one-off templates.
     *
     *   $view->renderFile(BASEDIR . 'themes/default/errors/404.php', ['code' => 404]);
     *
     * Also accepts a theme-relative path:
     *
     *   $view->renderPath('errors/404', ['code' => 404]);
     */
    public function renderPath(string $path, array $data = []): string
    {
        $file = $this->themePath($path . '.php');
        if (!is_file($file)) {
            throw new RuntimeException("View file not found: {$file}");
        }
        return $this->renderFile($file, array_merge($this->vars, $data, ['view' => $this]));
    }

    /**
     * Render an inline closure with a layout.
     * Useful for controller actions that build output programmatically.
     *
     *   $view->inline(function($view) {
     *       echo '<p>Hello</p>';
     *   }, 'main');
     */
    public function inline(Closure $callback, string $layout = 'main', array $data = []): string
    {
        $data = array_merge($this->vars, $data, ['view' => $this]);
        $this->syncAssignedTitle('title', $data['title'] ?? null);

        ob_start();
        $callback($this);
        $content = (string) ob_get_clean();

        $layout = (defined('ADMIN_PANEL') && ADMIN_PANEL) ? 'admin' : $layout;
        if ($layout === '') {
            return $content;
        }
 
        $layoutFile = $this->findGlobalLayout($layout);
        return $this->renderFile($layoutFile, array_merge($data, ['content' => $content]));
    }

    // =========================================================================
    // PARTIALS
    // =========================================================================

    /**
     * Include a partial template, outputting directly.
     *
     * Resolution order:
     *   1. themes/{theme}/partials/{file}.php
     *   2. {moduleRoot}/views/partials/{file}.php   (if $moduleRoot is given)
     *
     *   <?php $view->partial('header') ?>
     *   <?php $view->partial('cards/post', ['post' => $post]) ?>
     *   <?php $view->partial('header', [], $moduleRoot) ?>
     *
     * @throws RuntimeException if the partial cannot be found.
     */
    public function partial(string $file, array $data = [], ?string $moduleRoot = null): string
    {
        $file = $this->sanitizeRelativePath($file);
        $data = array_merge($this->vars, $data, ['view' => $this]);

        $themePath = $this->themePath('partials/' . $file . '.php');
        if (is_file($themePath)) {
            return $this->renderFile($themePath, $data);
        }

        if ($moduleRoot !== null) {
            $modulePath = rtrim($moduleRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'views'
                . DIRECTORY_SEPARATOR . 'partials'
                . DIRECTORY_SEPARATOR . $file . '.php';
            if (is_file($modulePath)) {
                return $this->renderFile($modulePath, $data);
            }
        }

        throw new RuntimeException("Partial not found: '{$file}' (checked theme and module paths).");
    }

    /**
     * Return a rendered partial as a string instead of outputting it.
     */
    public function capturePartial(string $file, array $data = [], ?string $moduleRoot = null): string
    {
        return $this->partial($file, $data, $moduleRoot);
    }

    // =========================================================================
    // ESCAPING HELPERS
    // =========================================================================

    /**
     * HTML-escape a value for safe output in templates.
     *
     * Handles strings, arrays (recursively), and stringable objects.
     * Returns '[Object]' for objects with no __toString.
     *
     *   <?= $view->e($title) ?>
     *   <?= $view->e($tags) ?>  <!-- array → each element escaped -->
     */
    public function e(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([$this, 'e'], $value);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : '[Object]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value for use inside a JavaScript string literal.
     *
     *   <script>var name = <?= $view->ej($name) ?>;</script>
     */
    public function ej(mixed $value): string
    {
        return json_encode((string) $value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Escape a value for safe use in a URL query parameter.
     *
     *   <a href="/search?q=<?= $view->eu($query) ?>">
     */
    public function eu(mixed $value): string
    {
        return urlencode((string) $value);
    }

    // =========================================================================
    // UTILITY HELPERS
    // =========================================================================

    /**
     * Format a byte count into a human-readable string.
     *
     *   $view->formatBytes(1048576) → '1 MB'
     */
    public function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1024       => $bytes . ' B',
            $bytes < 1_048_576  => round($bytes / 1024, 2) . ' KB',
            $bytes < 1_073_741_824 => round($bytes / 1_048_576, 2) . ' MB',
            default             => round($bytes / 1_073_741_824, 2) . ' GB',
        };
    }

    /**
     * Return true when a named view file exists for the given controller + action.
     * Safe to call without triggering a render.
     *
     *   if ($view->viewExists($this, 'sidebar')) { ... }
     */
    public function viewExists(object $controller, string $action): bool
    {
        try {
            $ctrlFile = (new ReflectionClass($controller))->getFileName();
            $viewFile = $this->findViewFile($ctrlFile, $action);
            return is_file($viewFile);
        } catch (RuntimeException) {
            return false;
        }
    }

    // =========================================================================
    // LAYOUT & VIEW RESOLUTION
    // =========================================================================

    /**
     * Derive the view file path from a controller file and action name.
     *
     * Convention: controllers/foo.php + action 'index'
     *           → views/foo/index.php
     */
    protected function findViewFile(string $controllerFile, string $action): string
    {
        $action    = $this->sanitizeRelativePath($action);
        $viewsDir  = str_replace(
            DIRECTORY_SEPARATOR . 'controllers',
            DIRECTORY_SEPARATOR . 'views',
            dirname($controllerFile)
        );
        $controller = basename($controllerFile, '.php');
        $file       = $viewsDir . DIRECTORY_SEPARATOR . $controller . DIRECTORY_SEPARATOR . $action . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View file not found: {$file}");
        }

        return $file;
    }

    /**
     * Find a layout file.
     *
     * Resolution order:
     *   1. {moduleRoot}/views/layout/{layout}.php
     *   2. themes/{theme}/{layout}.php
     *   3. BASEDIR/views/layout/{layout}.php
     */
    protected function findLayout(string $moduleRoot, string $layout = 'main'): string
    {
        $moduleLayout = $moduleRoot . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . $layout . '.php';

        if (is_file($moduleLayout)) {
            return $moduleLayout;
        }

        return $this->findGlobalLayout($layout);
    }

    /**
     * Find a layout file without a module context.
     *
     * Resolution order:
     *   1. themes/{theme}/{layout}.php
     *   2. BASEDIR/views/layout/{layout}.php
     */
    protected function findGlobalLayout(string $layout = 'main'): string
    {
        $themeFile = $this->themePath($layout . '.php');
        if (is_file($themeFile)) {
            return $themeFile;
        }

        $globalFile = BASEDIR . 'views' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . $layout . '.php';
        if (is_file($globalFile)) {
            return $globalFile;
        }

        throw new RuntimeException("Layout '{$layout}' not found (checked theme and global paths).");
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Render a PHP file in an isolated scope and return its output as a string.
     *
     * Variables are injected via a closure so they cannot conflict with
     * internal local variables like $__file and $__data.
     */
    protected function renderFile(string $__file, array $__data = []): string
    {
        if (!is_file($__file)) {
            throw new RuntimeException("Template file not found: {$__file}");
        }

        ob_start();
        // Note: no named params here. The locals are $__file and $__data,
        // so extracted keys named 'file' or 'data' do not collide.
        (static function () use ($__file, $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        })();
        return (string) ob_get_clean();
    }

    /**
     * Derive the module root directory from any file inside it.
     * Looks for the last occurrence of /controllers/ in the path.
     *
     *   /app/modules/news/controllers/home.php → /app/modules/news
     */
    protected function moduleRootFromFile(string $file): string
    {
        $dir  = dirname($file);
        $root = strstr($dir, DIRECTORY_SEPARATOR . 'controllers', before_needle: true);

        if ($root === false) {
            throw new RuntimeException("Cannot determine module root from: {$file}");
        }

        return $root;
    }

    /**
     * Return the absolute base path for a theme directory.
     */
    private function themeBasePath(string $theme): string
    {
        return BASEDIR . 'themes' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR;
    }

    /**
     * Strip any character that could be used for path traversal or injection
     * from a relative path segment (partial name, action name, etc.).
     *
     * Allows: letters, digits, hyphens, underscores, forward slashes.
     *
     * @throws RuntimeException on traversal attempts.
     */
    private function sanitizeRelativePath(string $path): string
    {
        $path = str_replace(['\\', "\0"], ['/', ''], $path);
        $path = trim($path, '/');

        if (str_contains($path, '..') || !preg_match('#^[a-zA-Z0-9_\-/]+$#', $path)) {
            throw new RuntimeException("Invalid path: '{$path}'.");
        }

        return $path;
    }

    /**
     * Strip anything that is not a letter, digit, hyphen, or underscore
     * from a theme name. Falls back to 'default' on an empty result.
     */
    private function sanitizeThemeName(string $theme): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $theme);
        return $clean !== '' ? $clean : 'default';
    }

    /**
     * Add HTML to a named block.
     *
     *   $view->block('sidebar', $view->partial('widgets/recent'));
     *   $view->block('scripts', '<script src="..."></script>', prepend: true);
     */
    public function block(string $name, string $html, bool $prepend = false): static
    {
        $blocks = $this->registry->get('block');
        $prepend ? $blocks->prepend($name, $html) : $blocks->add($name, $html);
        return $this;
    }

    /**
     * Return the rendered HTML for a named block.
     *
     *   <?= $view->block('sidebar') ?>
     */
    public function getBlock(string $name): string
    {
        return $this->registry->get('block')->get($name);
    }

    /**
     * True when a block has content.
     *
     *   <?php if ($view->hasBlock('sidebar')): ?>
     *       <aside><?= $view->getBlock('sidebar') ?></aside>
     *   <?php endif; ?>
     */
    public function hasBlock(string $name): bool
    {
        return $this->registry->get('block')->has($name);
    }

    /**
     * Replace a block's contents.
     */
    public function setBlock(string $name, string $html): static
    {
        $this->registry->get('block')->set($name, $html);
        return $this;
    }

    // ---------------------------------------------------------------------
    // SEO metadata
    // ---------------------------------------------------------------------
    private function isValidAssetUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '//') || str_contains($url, '\\')) {
            return false;
        }

        if (preg_match('#\Ahttps?://#i', $url)) {
            $parts = parse_url($url);
            return is_array($parts)
                && isset($parts['scheme'], $parts['host'])
                && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                && $parts['host'] !== '';
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        if (!preg_match('#\A(?:\.\.?/|themes/|assets/|cdn/|modules/|system/)#i', $url)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function assertAssetUrl(string $url, string $type): void
    {
        if (!$this->isValidAssetUrl($url)) {
            throw new \InvalidArgumentException("Invalid {$type}: {$url}");
        }
    }

    /** @param list<string> $items */
    private function appendUnique(array &$items, string $value): void
    {
        if (!in_array($value, $items, true)) {
            $items[] = $value;
        }
    }

    public function setTitle(string $title): void
    {
        if (!in_array($title, $this->title, true)) {
            $this->title[] = $title;
        }
    }

    public function setTitleSeparator(string $sep): void
    {
        $this->title_separator = $sep !== '' ? $sep : ' · ';
    }

    private function assertNonEmptyMetadataName(string $name, string $label): void
    {
        $name = trim($name);
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new \InvalidArgumentException("Invalid {$label}.");
        }
    }

    public function getTitle(): string
    {
        return implode($this->title_separator, array_reverse($this->title));
    }

    public function pageTitle(string $fallback = ''): string
    {
        $built = $this->getTitle();
        if ($built !== '') {
            return $built;
        }
        $assigned = $this->vars['title'] ?? '';
        return is_string($assigned) && $assigned !== '' ? $assigned : $fallback;
    }

    /** Page heading for the admin topbar — last assigned title, without the site suffix. */
    public function heading(string $fallback = ''): string
    {
        $assigned = $this->vars['title'] ?? '';
        if (is_string($assigned) && trim($assigned) !== '') {
            return trim($assigned);
        }
        if ($this->title !== []) {
            return (string) $this->title[array_key_last($this->title)];
        }
        return $fallback;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setKeywords(string $keywords): void
    {
        $this->keywords = $keywords;
    }

    public function getKeywords(): string
    {
        return $this->keywords;
    }

    public function setMeta(string $name, string $content): void
    {
        $this->assertNonEmptyMetadataName($name, 'meta name');
        $this->meta[$name] = $content;
    }

    /** @return array<string, string> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function setOG(string $property, string $content): void
    {
        $this->assertNonEmptyMetadataName($property, 'Open Graph property');
        $this->og_tags[$property] = $content;
    }

    /** @return array<string, string> */
    public function getOG(): array
    {
        return $this->og_tags;
    }


    public function addStyle(string $href): void
    {
        $this->assertAssetUrl($href, 'stylesheet URL');
        $this->appendUnique($this->styles, trim($href));
    }

    /** Queue a theme stylesheet, e.g. css/modules/news.css */
    public function addThemeStyle(string $path): void
    {
        $this->addStyle($this->asset($path));
    }

    /**
     * Return a cache-busted URL for a module asset.
     *
     *   $view->moduleAsset('forum', 'css/forum.css')
     *   → http://localhost/demo2/modules/forum/asset/css/forum.css?v=1710000000
     *
     * Falls back to the theme asset if the module file doesn't exist, so a
     * missing module style doesn't break the page.
     */
    public function moduleAsset(string $module, string $path): string
    {
        $module = preg_replace('/[^A-Za-z0-9_-]/', '', $module);
        if ($module === '') {
            throw new RuntimeException('Invalid module name for asset.');
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException("Invalid asset path: '{$path}'.");
        }

        $base = rtrim($this->request->baseUrl(), '/');

        $candidates = [
            'modules/'        . $module . '/assets/' . $path,
            'system/modules/' . $module . '/assets/' . $path,
        ];

        foreach ($candidates as $rel) {
            $fullPath = BASEDIR . $rel;
            if (is_file($fullPath)) {
                return $base . '/' . $rel . '?v=' . (string) filemtime($fullPath);
            }
        }

        // Fallback to the theme asset so nothing 404s.
        return $this->asset($path);
    }

    /**
     * Queue a module stylesheet.
     *
     *   $view->addModuleStyle('forum', 'css/forum.css');
     */
    public function addModuleStyle(string $module, string $path): void
    {
        $this->addStyle($this->moduleAsset($module, $path));
    }

    /**
     * Queue a module script.
     *
     *   $view->addModuleScript('forum', 'js/forum.js');
     *   $view->addModuleScript('forum', 'js/forum.js', false);   // header
     */
    public function addModuleScript(string $module, string $path, bool $in_footer = true): void
    {
        $this->addScript($this->moduleAsset($module, $path), $in_footer);
    }

    /** @return list<string> */
    public function getStyles(): array
    {
        return $this->styles;
    }

    public function addScript(string $src, bool $in_footer = true): void
    {
        $this->assertAssetUrl($src, 'script URL');

        if ($in_footer) {
            $this->appendUnique($this->scripts_footer, trim($src));
        } else {
            $this->appendUnique($this->scripts_header, trim($src));
        }
    }

    /** @return list<string> */
    public function getScripts(string $location = 'footer'): array
    {
        return strtolower($location) === 'header'
            ? $this->scripts_header
            : $this->scripts_footer;
    }

    public function addInlineStyle(string $style): void
    {
        $this->appendUnique($this->inline_styles, $style);
    }

    /** @return list<string> */
    public function getInlineStyles(): array
    {
        return $this->inline_styles;
    }

    public function addInlineScript(string $script): void
    {
        $this->appendUnique($this->inline_scripts, $script);
    }

    /** @return list<string> */
    public function getInlineScripts(): array
    {
        return $this->inline_scripts;
    }

    public function addDependency(string $src): void
    {
        if (trim($src) === '') {
            throw new \InvalidArgumentException('Asset dependency cannot be empty.');
        }

        $this->appendUnique($this->dependencies, trim($src));
    }

    /** @return list<string> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Safely return empty string if accidentally cast to string.
     * Prevents "Object could not be converted to string" errors.
     */
    public function __toString(): string
    {
        return '';
    }
}