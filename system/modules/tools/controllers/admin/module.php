<?php
declare(strict_types=1);

use System\Console\MigrateCommand;
use System\Engine\Controller;

class ToolsAdminModule extends Controller
{
    // =========================================================================
    // List
    // =========================================================================

    public function indexAction(): void
    {
        $installed = $this->getInstalled();
        $available = $this->discoverAvailable();
        $migrator  = new MigrateCommand($this->registry);

        $q = trim((string) $this->request->get('q', 'string', ''));
        $filter = trim((string) $this->request->get('filter', 'string', ''));

        $toolsCfg = $this->readSavedConfig('tools');
        $showSystem = !array_key_exists('show_system_modules', $toolsCfg)
            || !empty($toolsCfg['show_system_modules']);

        $modules = [];
        foreach ($available as $name => $info) {
            $isSystem = $this->isSystemModule($name);
            if (!$showSystem && $isSystem) {
                continue;
            }

            $health = ['total' => 0, 'applied' => 0, 'pending' => 0, 'pending_keys' => []];
            try {
                $health = $migrator->moduleHealth($name);
            } catch (\Throwable) {
                // migrations table may be unavailable
            }

            $row = array_merge($info, [
                'installed'     => isset($installed[$name]),
                'enabled'       => (bool) ($installed[$name]['enabled'] ?? false),
                'version'       => $installed[$name]['version'] ?? $info['version'] ?? '1.0.0',
                'disk_version'  => $info['version'] ?? '1.0.0',
                'db_id'         => $installed[$name]['id'] ?? null,
                'installed_at'  => $installed[$name]['installed_at'] ?? null,
                'is_system'     => $isSystem,
                'has_config'    => is_file(rtrim($info['path'], '/\\') . DIRECTORY_SEPARATOR . 'config.json'),
                'migrations'    => $health,
            ]);

            if ($q !== '') {
                $hay = strtolower($row['name'] . ' ' . $row['label'] . ' ' . ($row['description'] ?? ''));
                if (!str_contains($hay, strtolower($q))) {
                    continue;
                }
            }

            $match = match ($filter) {
                'installed' => $row['installed'],
                'enabled'   => $row['installed'] && $row['enabled'],
                'disabled'  => $row['installed'] && !$row['enabled'],
                'pending'   => ($row['migrations']['pending'] ?? 0) > 0,
                'system'    => $row['is_system'],
                default     => true,
            };
            if (!$match) {
                continue;
            }

            $modules[$name] = $row;
        }

        ksort($modules);

        $pendingTotal = 0;
        foreach ($modules as $m) {
            $pendingTotal += (int) ($m['migrations']['pending'] ?? 0);
        }

        echo $this->output('index', [
            'title'        => 'Module Management',
            'modules'      => $modules,
            'q'            => $q,
            'filter'       => $filter,
            'pendingTotal' => $pendingTotal,
            'showSystem'   => $showSystem,
        ]);
    }

    public function installAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $name = trim((string) $this->request->post('name', 'string', ''));
        if ($name === '') {
            $this->notify->error('Module name required.');
            redirect_to('tools/admin/module');
            return;
        }

        $modulePath = $this->getModulePath($name);
        if (!is_dir($modulePath)) {
            throw new RuntimeException("Module '{$name}' not found at {$modulePath}");
        }

        if ($this->isInstalled($name)) {
            $this->notify->warning("Module '{$name}' is already installed.");
            redirect_to('tools/admin/module');
            return;
        }

        $this->db->insert('modules', [
            'name'         => $name,
            'label'        => $this->getModuleLabel($name),
            'version'      => $this->getModuleVersion($name),
            'enabled'      => 1,
            'config'       => '{}',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            (new MigrateCommand($this->registry))->runModule($name);
            $this->runInstallScript($name, $modulePath);
        } catch (\Throwable $e) {
            $this->db->delete('modules', ['name' => $name]);
            $this->notify->error("Install failed for '{$name}': " . $e->getMessage());
            redirect_to('tools/admin/module');
            return;
        }

        $this->notify->success("Module '{$name}' installed.");
        redirect_to('tools/admin/module');
    }

    public function uninstallAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $name = (string) $this->request->post('name', 'string', '');

        if ($this->isSystemModule($name)) {
            $this->notify->error("System module '{$name}' cannot be uninstalled.");
            redirect_to('tools/admin/module');
            return;
        }

        if ($name === '' || !$this->isInstalled($name)) {
            $this->notify->error('Module not found or not installed.');
            redirect_to('tools/admin/module');
            return;
        }

        $path = $this->getModulePath($name);

        $errors = [];
        try {
            (new MigrateCommand($this->registry))->rollbackModule($name);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // Always run uninstall.php when present — cleans leftover FKs / orphan tables
        // after migration consolidations where old down() files are gone.
        try {
            $this->runUninstallScript($name, $path);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($errors !== []) {
            $this->notify->error("Uninstall failed for '{$name}': " . implode(' | ', $errors));
            redirect_to('tools/admin/module');
            return;
        }

        $this->db->delete('modules', ['name' => $name]);
        $this->notify->success("Module '{$name}' uninstalled.");
        redirect_to('tools/admin/module');
    }

    public function enableAction(): void
    {
        $this->setEnabled((string) $this->request->post('name', 'string', ''), true);
    }

    public function disableAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();
        $name = (string) $this->request->post('name', 'string', '');
        if ($this->isSystemModule($name)) {
            $this->notify->error("System module '{$name}' cannot be disabled.");
            redirect_to('tools/admin/module');
            return;
        }
        $this->setEnabled($name, false);
    }

    public function migrateAction(): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        $name = trim((string) $this->request->post('name', 'string', ''));
        if ($name === '' || !$this->isInstalled($name)) {
            $this->notify->error('Module not found or not installed.');
            redirect_to('tools/admin/module');
            return;
        }

        try {
            ob_start();
            (new MigrateCommand($this->registry))->runModule($name);
            $out = trim((string) ob_get_clean());
            $this->notify->success($out !== '' ? $out : "Migrations ran for '{$name}'.");
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->notify->error("Migration failed for '{$name}': " . $e->getMessage());
        }

        redirect_to('tools/admin/module', array_filter([
            'q'      => trim((string) $this->request->post('q', 'string', '')),
            'filter' => trim((string) $this->request->post('filter', 'string', '')),
        ], static fn($v) => $v !== ''));
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    public function configAction(): void
    {
        $name = trim((string) $this->request->get('name', 'string', ''));

        if ($name === '' || !$this->isInstalled($name)) {
            $this->notify->error("Module '{$name}' is not installed.");
            redirect_to('tools/admin/module');
            return;
        }

        $module = $this->getModuleInfo($name);
        if ($module === null) {
            $this->notify->error('Module not found.');
            redirect_to('tools/admin/module');
            return;
        }

        $schema = $this->loadConfigSchema($name, $module['path']);

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $this->saveConfig($name, $schema);
            return;
        }

        $saved = $module['config'] ? (json_decode((string) $module['config'], true) ?: []) : [];
        if ($name === 'user') {
            // Prefer live system/config/user.php values.
            $saved = array_replace($saved, [
                'registration_open' => (bool) $this->config->get('user.registration_open', true),
                'min_form_seconds'  => (int) $this->config->get('user.min_form_seconds', 5),
                'default_group'     => (string) $this->config->get('user.default_group', 'users'),
            ]);
        }

        $config = array_replace_recursive($this->schemaToDefaults($schema), is_array($saved) ? $saved : []);

        $health = ['total' => 0, 'applied' => 0, 'pending' => 0, 'pending_keys' => []];
        try {
            $health = (new MigrateCommand($this->registry))->moduleHealth($name);
        } catch (\Throwable) {
        }

        echo $this->output('config', [
            'title'  => "Configure: {$module['label']}",
            'module' => $module + ['migrations' => $health, 'is_system' => $this->isSystemModule($name)],
            'schema' => $schema,
            'config' => $config,
        ]);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function isSystemModule(string $name): bool
    {
        return in_array($name, ['tools', 'user'], true);
    }

    private function setEnabled(string $name, bool $enabled): void
    {
        if (!$this->request->isPost()) {
            throw new RuntimeException('Method not allowed');
        }
        $this->guardCsrf();

        if (!$this->isInstalled($name)) {
            $this->notify->error("Module '{$name}' is not installed.");
            redirect_to('tools/admin/module');
            return;
        }

        $this->db->update('modules',
            ['enabled' => (int) $enabled],
            ['name' => $name]
        );

        $this->notify->success($enabled
            ? "Module '{$name}' enabled."
            : "Module '{$name}' disabled."
        );
        redirect_to('tools/admin/module');
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('tools/admin/module');
            exit;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function discoverAvailable(): array
    {
        $modules = [];
        $roots = [
            BASEDIR . 'modules' . DIRECTORY_SEPARATOR,
            BASEDIR . 'system' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR,
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (isset($modules[$name])) {
                    continue;
                }
                $modules[$name] = [
                    'name'        => $name,
                    'path'        => $dir,
                    'label'       => $this->getModuleLabel($name, $dir),
                    'version'     => $this->getModuleVersion($name, $dir),
                    'description' => $this->getModuleDescription($name, $dir),
                    'root'        => $root,
                ];
            }
        }
        return $modules;
    }

    /** @return array<string, array<string, mixed>> */
    private function getInstalled(): array
    {
        $rows = $this->db->query("SELECT * FROM #__modules ORDER BY name ASC")->rows;
        $list = [];
        foreach ($rows as $row) {
            $list[(string) $row['name']] = [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'enabled'      => (bool) $row['enabled'],
                'version'      => $row['version'],
                'config'       => $row['config'],
                'installed_at' => $row['installed_at'] ?? null,
            ];
        }
        return $list;
    }

    private function isInstalled(string $name): bool
    {
        return (bool) $this->db->first('modules', ['name' => $name]);
    }

    private function getModulePath(string $name): string
    {
        $candidates = [
            BASEDIR . 'modules' . DIRECTORY_SEPARATOR . $name,
            BASEDIR . 'system' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $name,
        ];
        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        return $candidates[0];
    }

    private function getModuleLabel(string $name, ?string $path = null): string
    {
        $meta = $this->readComposerExtra($name, $path);
        return (string) ($meta['label'] ?? ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    private function getModuleVersion(string $name, ?string $path = null): string
    {
        $path ??= $this->getModulePath($name);
        $composer = $path . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string) $data['version'];
            }
        }
        return '1.0.0';
    }

    private function getModuleDescription(string $name, ?string $path = null): string
    {
        $meta = $this->readComposerExtra($name, $path);
        if (!empty($meta['description'])) {
            return (string) $meta['description'];
        }
        $path ??= $this->getModulePath($name);
        $composer = $path . '/composer.json';
        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);
            if (is_array($data) && !empty($data['description'])) {
                return (string) $data['description'];
            }
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function readComposerExtra(string $name, ?string $path = null): array
    {
        $path ??= $this->getModulePath($name);
        $composer = $path . '/composer.json';
        if (!is_file($composer)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($composer), true);
        if (!is_array($data)) {
            return [];
        }
        $extra = $data['extra'] ?? [];
        return is_array($extra) ? $extra : [];
    }

    private function runInstallScript(string $name, string $path): void
    {
        $script = $path . '/install.php';
        if (is_file($script)) {
            require $script;
        }
    }

    private function runUninstallScript(string $name, string $path): void
    {
        $script = $path . '/uninstall.php';
        if (!is_file($script)) {
            return;
        }
        $db = $this->db;
        $registry = $this->registry;
        require $script;
    }

    /** @return array<string, mixed>|null */
    private function getModuleInfo(string $name): ?array
    {
        $row = $this->db->first('modules', ['name' => $name]);
        if (!$row) {
            return null;
        }
        $path = $this->getModulePath($name);
        return [
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'label'        => $row['label'] ?: $this->getModuleLabel($name, $path),
            'version'      => $row['version'],
            'disk_version' => $this->getModuleVersion($name, $path),
            'enabled'      => (bool) $row['enabled'],
            'config'       => $row['config'],
            'path'         => $path,
            'description'  => $this->getModuleDescription($name, $path),
            'installed_at' => $row['installed_at'] ?? null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadConfigSchema(string $name, string $path): array
    {
        $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'config.json';
        if (!is_file($file)) {
            return [];
        }
        $schema = json_decode((string) file_get_contents($file), true);
        return is_array($schema) ? $schema : [];
    }

    /** @return array<string, mixed> */
    private function schemaToDefaults(array $schema): array
    {
        $defaults = [];
        foreach ($schema as $field) {
            $key = $field['name'] ?? '';
            if ($key === '') {
                continue;
            }
            $defaults[$key] = $field['default'] ?? match ($field['type'] ?? 'text') {
                'number'   => 0,
                'checkbox' => false,
                'select', 'radio' => $field['options'][0]['value'] ?? '',
                'array'    => [],
                default    => '',
            };
        }
        return $defaults;
    }

    /** @return array<string, mixed> */
    private function readSavedConfig(string $name): array
    {
        $row = $this->db->first('modules', ['name' => $name]);
        if (!$row || empty($row['config'])) {
            return [];
        }
        $decoded = json_decode((string) $row['config'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveConfig(string $name, array $schema): void
    {
        $input = (array) $this->request->post('config', 'raw', []);
        $clean = [];

        foreach ($schema as $field) {
            $key = $field['name'] ?? '';
            if ($key === '') {
                continue;
            }

            $value = $input[$key] ?? null;
            $type = $field['type'] ?? 'text';

            if ($type === 'array') {
                if (is_string($value)) {
                    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
                    $clean[$key] = array_values(array_filter(array_map('trim', $lines), static fn($v) => $v !== ''));
                } elseif (is_array($value)) {
                    $clean[$key] = array_values(array_filter(array_map('trim', $value), static fn($v) => $v !== ''));
                } else {
                    $clean[$key] = [];
                }
                continue;
            }

            $clean[$key] = match ($type) {
                'number'   => is_numeric($value) ? (0 + $value) : (float) ($field['default'] ?? 0),
                'checkbox' => !empty($value),
                default    => is_scalar($value) ? trim((string) $value) : $value,
            };
        }

        $this->db->update('modules',
            ['config' => json_encode($clean, JSON_UNESCAPED_UNICODE)],
            ['name' => $name]
        );

        // Keep system/config/user.php in sync so RegistrationGuard keeps working.
        if ($name === 'user') {
            $this->config->set('user.registration_open', !empty($clean['registration_open']));
            $this->config->set('user.min_form_seconds', max(3, (int) ($clean['min_form_seconds'] ?? 5)));
            $this->config->set('user.default_group', (string) ($clean['default_group'] ?? 'users'));
        }

        $this->notify->success('Configuration saved.');
        redirect_to('tools/admin/module/config', ['name' => $name]);
    }
}
