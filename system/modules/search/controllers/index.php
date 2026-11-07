<?php
declare(strict_types=1);

use System\Engine\Controller;

class SearchIndex extends Controller
{
    private const LIMIT = 8;

    /** @var array<string, int> */
    private const ORDER = [
        'news'  => 1,
        'forum' => 2,
        'store' => 3,
        'quran' => 4,
        'media' => 5,
        'page'  => 6,
        'node'  => 7,
    ];

    public function indexAction(): void
    {
        $q = trim((string) $this->request->get('q', 'string', ''));
        $groups = $q === '' ? [] : $this->collect($q);
        $total = $this->hitCount($groups);
        $title = $q !== '' ? 'Search: ' . $q : 'Search';

        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('search', 'search.css');

        echo $this->view->inline(function () use ($q, $groups, $total): void {
            include get_module_path('search') . '/views/index.php';
        }, 'main', [
            'title'  => $title,
            'q'      => $q,
            'groups' => $groups,
            'total'  => $total,
        ]);
    }

    /**
     * @return list<array{module: string, label: string, more: string, hits: list<array<string, string>>}>
     */
    private function collect(string $q): array
    {
        $modules = [];
        try {
            $modules = $this->db->query(
                "SELECT `name`, `label` FROM `#__modules` WHERE `enabled` = 1 ORDER BY `label` ASC, `name` ASC"
            )->rows ?? [];
        } catch (Throwable) {
            return [];
        }

        $groups = [];
        foreach ($modules as $module) {
            $name = strtolower(trim((string) ($module['name'] ?? '')));
            if ($name === '' || !preg_match('/^[a-z0-9_-]+$/', $name)) {
                continue;
            }

            $path = get_module_path($name);
            if ($path === null) {
                continue;
            }

            $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'search.php';
            if (!is_file($file)) {
                continue;
            }

            try {
                $provider = include $file;
                if (!is_callable($provider)) {
                    continue;
                }
                $group = $provider($q, $this->db, self::LIMIT);
            } catch (Throwable) {
                continue;
            }

            if (!is_array($group) || empty($group['hits']) || !is_array($group['hits'])) {
                continue;
            }

            $hits = [];
            foreach ($group['hits'] as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                $title = trim((string) ($hit['title'] ?? ''));
                $url = trim((string) ($hit['url'] ?? ''));
                if ($title === '' || $url === '') {
                    continue;
                }
                $hits[] = [
                    'title'   => $title,
                    'url'     => $url,
                    'snippet' => (string) ($hit['snippet'] ?? ''),
                    'meta'    => (string) ($hit['meta'] ?? ''),
                    'image'   => (string) ($hit['image'] ?? ''),
                ];
                if (count($hits) >= self::LIMIT) {
                    break;
                }
            }

            if ($hits === []) {
                continue;
            }

            $groups[] = [
                'module' => $name,
                'label'  => trim((string) ($group['label'] ?? $module['label'] ?? ucfirst($name))) ?: ucfirst($name),
                'more'   => trim((string) ($group['more'] ?? '')),
                'hits'   => $hits,
            ];
        }

        usort($groups, static function (array $a, array $b): int {
            $left = self::ORDER[$a['module']] ?? 50;
            $right = self::ORDER[$b['module']] ?? 50;
            if ($left !== $right) {
                return $left <=> $right;
            }
            return strcasecmp($a['label'], $b['label']);
        });

        return $groups;
    }

    /**
     * @param list<array{hits: list<array<string, string>>}> $groups
     */
    private function hitCount(array $groups): int
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += count($group['hits']);
        }
        return $total;
    }
}
