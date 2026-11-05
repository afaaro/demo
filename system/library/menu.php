<?php
declare(strict_types=1);

namespace System\Library;

final class Menu
{
    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /**
     * @param array{
     *     label: string,
     *     route: string,
     *     args?: array<string, mixed>,
     *     icon?: string,
     *     visible?: callable,
     *     position?: int,
     *     children?: array<int, array<string, mixed>>
     * } $item
     */
    public function add(array $item): void
    {
        $item['label']    = (string) ($item['label'] ?? '');
        $item['route']    = (string) ($item['route'] ?? '');
        $item['position'] = (int)    ($item['position'] ?? 100);

        if ($item['label'] === '') {
            return;
        }

        $this->items[] = $item;
    }

    public function all(): array
    {
        $items = $this->items;

        // Filter invisible items
        $items = array_values(array_filter($items, static function (array $item): bool {
            if (isset($item['permission']) && is_string($item['permission']) && $item['permission'] !== '') {
                $perm = $item['permission'];
                if (str_ends_with($perm, '.view')) {
                    $module = substr($perm, 0, -strlen('.view'));
                    if ($module !== '' && \System\Services\User::canModuleCurrent($module)) {
                        return true;
                    }
                }
                if (!\System\Services\User::canCurrent($perm)) {
                    return false;
                }
            }
            if (!isset($item['visible']) || !is_callable($item['visible'])) {
                return true;
            }
            try {
                return (bool) $item['visible']();
            } catch (\Throwable) {
                return false;
            }
        }));

        // Sort by position, then by label
        usort($items, static function (array $a, array $b): int {
            $cmp = ($a['position'] ?? 100) <=> ($b['position'] ?? 100);
            return $cmp !== 0 ? $cmp : strcmp((string) $a['label'], (string) $b['label']);
        });

        return $items;
    }

    public function clear(): void
    {
        $this->items = [];
    }
}