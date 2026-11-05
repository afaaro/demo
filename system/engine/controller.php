<?php
declare(strict_types=1);
namespace System\Engine;

use ReflectionClass;
use RuntimeException;
use System\Engine\Registry;

/**
 * Controller — base class for all module controllers.
 *
 * Built-in shortcuts:
 *   $this->db, $this->view, $this->request, $this->config, $this->session
 *   $this->notify, $this->auth (current User), $this->log, $this->form, $this->load, ...
 *
 * Load module services without FQCNs:
 *   $this->load->service('OrderRepository');
 *   $this->load->service('system/UserSessionService');
 *
 * @property-read Loader $load
 */
abstract class Controller
{
    protected Registry $registry;

    /** @var array<string, mixed> Cached resolved services */
    private array $serviceCache = [];

    // Module metadata (cached)
    private ?string $resolvedModuleRoot = null;
    private ?string $resolvedModuleName = null;

    // =========================================================================
    // Construction
    // =========================================================================

    final public function __construct(Registry $registry)
    {
        $this->registry = $registry;
        $this->boot();
    }

    /**
     * Override in subclass: runs after construction.
     *
     * protected function boot(): void
     * {
     *     $this->view->assign('user', $this->auth->user());
     * }
     */
    protected function boot(): void {}

    /**
     * Override in subclass: runs after action, before response.
     * Default: ages flash messages & old input — messages survive redirects.
     */
    protected function after(): void
    {
        $this->session->ageFlash();
        $this->session->ageInput();
    }

    // =========================================================================
    // MAGIC — Lazy Service Resolution
    // =========================================================================

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->serviceCache)) {
            return $this->serviceCache[$key];
        }

        // Module-scoped loader (not a registry singleton — needs this controller's module).
        if ($key === 'load') {
            return $this->serviceCache['load'] = new Loader($this->registry, $this->moduleName());
        }

        // Current user: never cache. Login/logout in the same request must see the new row.
        if ($key === 'auth' || $key === 'user') {
            if (!$this->registry->has($key)) {
                throw new RuntimeException(
                    "Controller: service '{$key}' not registered. "
                    . 'Available: ' . implode(', ', [...$this->registry->keys(), 'load'])
                );
            }
            return $this->registry->get($key);
        }

        if (!$this->registry->has($key)) {
            throw new RuntimeException(
                "Controller: service '{$key}' not registered. "
                . 'Available: ' . implode(', ', [...$this->registry->keys(), 'load'])
            );
        }

        return $this->serviceCache[$key] = $this->registry->get($key);
    }

    public function __isset(string $key): bool
    {
        return $key === 'load' || $this->registry->has($key);
    }

    // =========================================================================
    // MODULE METADATA
    // =========================================================================

    public function moduleRoot(): string
    {
        if ($this->resolvedModuleRoot !== null) {
            return $this->resolvedModuleRoot;
        }

        $file = (new ReflectionClass($this))->getFileName();
        $root = strstr(dirname($file), DS . 'controllers', true);

        if ($root === false) {
            throw new RuntimeException("Cannot determine module root from: {$file}");
        }

        return $this->resolvedModuleRoot = $root;
    }

    public function moduleName(): string
    {
        if ($this->resolvedModuleName !== null) {
            return $this->resolvedModuleName;
        }
        return $this->resolvedModuleName = basename($this->moduleRoot());
    }

    public function modulePath(string $relative = ''): string
    {
        $relative = ltrim(str_replace(['\\', '/'], DS, $relative), DS);
        return $this->moduleRoot() . ($relative !== '' ? DS . $relative : '');
    }

    // =========================================================================
    // RENDERING + LIFECYCLE
    // =========================================================================

    protected function output(
        string $action = 'index',
        array  $data   = [],
        string $layout = 'main',
    ): string {
        return $this->view
            ->assign($data)
            ->render($this, $action, $layout);
    }

    /**
     * Call this from your front controller after invoking the action.
     * It triggers the after() hook — override to add custom cleanup logic.
     */
    public function complete(): void
    {
        $this->after();
    }
}