<?php
declare(strict_types=1);

namespace System\Library;

use System\Engine\Registry;

class Notify
{
    public const ERROR   = 'error';
    public const NOTICE  = 'notice';
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const DANGER  = 'danger';

    private const TYPE_MAP = [
        self::ERROR   => 'danger',
        self::NOTICE  => 'info',
        self::SUCCESS => 'success',
        self::WARNING => 'warning',
        self::DANGER  => 'danger',
    ];

    /** @var array<string, int> Seconds before auto-dismiss; 0 = stay open */
    public static array $defaultTimeouts = [
        self::SUCCESS => 5,
        self::NOTICE  => 5,
        self::WARNING => 5,
        self::ERROR   => 8,
        self::DANGER  => 8,
    ];

    public string $wrapper = '<div class="notifications">%s</div>';

    public string $alertTemplate = <<<HTML
<div class="alert alert-{{type}} alert-dismissible fade show" data-timeout="{{timeout}}" role="alert">
    {{message}}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
HTML;

    public bool $autoDismiss = true;

    private bool $scriptQueued = false;

    public function __construct(
        protected Registry $registry
    ) {}

    // =========================================================================
    // Add Messages
    // =========================================================================

    public function add(string $type, string|array $messages, ?int $timeout = null): void
    {
        if (!in_array($type, [self::ERROR, self::NOTICE, self::SUCCESS, self::WARNING, self::DANGER], true)) {
            return;
        }

        $timeout = max(0, $timeout ?? (self::$defaultTimeouts[$type] ?? 5));
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        $session = $this->registry->get('session');
        foreach ($messages as $msg) {
            $text = trim((string) $msg);
            if ($text === '') {
                continue;
            }
            $session->flash($type, $text, ['timeout' => $timeout]);
        }
    }

    public function success(string|array $message, ?int $timeout = null): void
    {
        $this->add(self::SUCCESS, $message, $timeout);
    }

    public function error(string|array $message, ?int $timeout = null): void
    {
        $this->add(self::ERROR, $message, $timeout);
    }

    public function warning(string|array $message, ?int $timeout = null): void
    {
        $this->add(self::WARNING, $message, $timeout);
    }

    public function notice(string|array $message, ?int $timeout = null): void
    {
        $this->add(self::NOTICE, $message, $timeout);
    }

    public function danger(string|array $message, ?int $timeout = null): void
    {
        $this->add(self::DANGER, $message, $timeout);
    }

    // =========================================================================
    // Render
    // =========================================================================

    public function display(): string
    {
        $session = $this->registry->get('session');
        $flashes = $session->pullFlashes();
        if ($flashes === []) {
            return '';
        }

        $html = '';
        foreach ($flashes as $flash) {
            $type          = (string) ($flash['type'] ?? self::NOTICE);
            $message       = (string) ($flash['message'] ?? '');
            $context       = is_array($flash['context'] ?? null) ? $flash['context'] : [];
            $bootstrapType = self::TYPE_MAP[$type] ?? 'secondary';
            $timeout       = (int) ($context['timeout'] ?? self::$defaultTimeouts[$type] ?? 5);
            $escaped       = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Avoid sprintf() — messages may contain "%" characters.
            $html .= strtr($this->alertTemplate, [
                '{{type}}'    => htmlspecialchars($bootstrapType, ENT_QUOTES, 'UTF-8'),
                '{{timeout}}' => (string) max(0, $timeout),
                '{{message}}' => $escaped,
            ]);
        }

        if ($this->wrapper !== '') {
            $html = sprintf($this->wrapper, $html);
        }

        if ($this->autoDismiss && !$this->scriptQueued) {
            $this->scriptQueued = true;
            $view = $this->registry->get('view');
            $view->block('scripts', $this->buildScript());
        }

        return $html;
    }

    public function clear(): void
    {
        $this->registry->get('session')->clearFlashes();
    }

    public function hasMessages(): bool
    {
        return $this->registry->get('session')->hasFlashes();
    }

    public function buildScript(): string
    {
        return <<<'HTML'
<script>
(function () {
    'use strict';

    function setupAlert(el) {
        if (el.dataset.timeoutSetup === 'done') return;
        el.dataset.timeoutSetup = 'done';

        var seconds = parseInt(el.getAttribute('data-timeout'), 10);
        if (!Number.isFinite(seconds) || seconds <= 0) {
            return;
        }

        setTimeout(function () {
            if (!el || !el.isConnected) return;
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                bootstrap.Alert.getOrCreateInstance(el).close();
            } else {
                el.classList.remove('show');
                el.classList.add('fade');
                setTimeout(function () { el.remove(); }, 150);
            }
        }, seconds * 1000);
    }

    function initAll() {
        document.querySelectorAll('.alert[data-timeout]').forEach(setupAlert);
    }

    function start() {
        initAll();
        if (!document.body) return;
        var obs = new MutationObserver(initAll);
        obs.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
HTML;
    }
}
