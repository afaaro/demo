<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Media\Services\ChannelRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class MediaAdminChannels extends Controller
{
    protected function boot(): void
    {
        media_require_admin();
    }

    public function indexAction(): void
    {
        $repo = new ChannelRepository($this->db);

        $dataset = (new Dataset($this->registry))
            ->perPage(20)
            ->searchPlaceholder('Channel title or slug…')
            ->sortable(['title', 'created_at', 'videos'])
            ->defaultSort('created_at', 'desc')
            ->columns([
                'title'      => 'Channel',
                'owner_name' => 'Owner',
                'videos'     => 'Videos',
                'created_at' => 'Created',
                'actions'    => '',
            ])
            ->query(function ($db, array $state) use ($repo): array {
                $result = $repo->adminSearch(
                    $state['q'],
                    $state['per_page'],
                    $state['offset'],
                    $state['sort'],
                    $state['dir']
                );
                return [
                    'rows'  => array_map(static fn(array $row): array => ['_row' => $row], $result['rows']),
                    'total' => $result['total'],
                ];
            });

        $e = fn(string|int|float $v): string => $this->view->e((string) $v);

        $dataset->columns([
            'title' => function (array $row) use ($e): string {
                $r = $row['_row'];
                return '<a class="sf-title" href="'
                    . $e($this->url->to('media/channel', ['id' => (int) $r['channel_id']]))
                    . '" target="_blank" rel="noopener">' . $e((string) $r['title']) . '</a>'
                    . '<span class="sf-sub">' . $e((string) $r['slug']) . '</span>';
            },
            'owner_name' => function (array $row) use ($e): string {
                return $e((string) ($row['_row']['owner_name'] ?? '—'));
            },
            'videos' => function (array $row): string {
                return number_format((int) ($row['_row']['video_count'] ?? 0));
            },
            'created_at' => function (array $row) use ($e): string {
                $ts = strtotime((string) $row['_row']['created_at']);
                return $e($ts ? date('M j, Y', $ts) : '');
            },
            'actions' => function (array $row) use ($e): string {
                $id = (int) $row['_row']['channel_id'];
                return '<a class="sf-btn is-sm" href="'
                    . $e($this->url->to('media/studio', ['channel_id' => $id]))
                    . '">Studio</a>';
            },
        ]);

        $this->view->assign('title', 'Media channels');
        echo $this->view->inline(static function () use ($dataset): void {
            echo '<div class="sf-page"><div class="sf-head"><div><h1>Channels</h1>'
               . '<p>Creator channels on this site.</p></div></div>';
            $dataset->render();
            echo '</div>';
        }, 'admin', ['title' => 'Media channels']);
    }
}
