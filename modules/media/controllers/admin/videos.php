<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Media\Services\VideoRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class MediaAdminVideos extends Controller
{
    protected function boot(): void
    {
        media_require_admin();
    }

    public function indexAction(): void
    {
        $repo = new VideoRepository($this->db);

        $dataset = (new Dataset($this->registry))
            ->perPage(20)
            ->searchPlaceholder('Title or channel…')
            ->sortable(['title', 'status', 'views_count', 'created_at'])
            ->defaultSort('created_at', 'desc')
            ->filter('status', 'select', [
                ''           => 'All statuses',
                'public'     => 'Public',
                'private'    => 'Private',
                'processing' => 'Processing',
            ])
            ->filter('type', 'select', [
                ''      => 'All types',
                'video' => 'Video',
                'audio' => 'Audio',
                'image' => 'Image',
                'embed' => 'Embed',
            ])
            ->columns([
                'title'       => 'Title',
                'channel'     => 'Channel',
                'type'        => 'Type',
                'status'      => 'Status',
                'views_count' => 'Views',
                'created_at'  => 'Created',
                'actions'     => '',
            ])
            ->query(function ($db, array $state) use ($repo): array {
                $result = $repo->adminSearch(
                    $state['q'],
                    (string) ($state['filters']['status'] ?? ''),
                    (string) ($state['filters']['type'] ?? ''),
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
                    . $e($this->url->to('media/watch', ['id' => (string) $r['video_id']]))
                    . '" target="_blank" rel="noopener">' . $e((string) $r['title']) . '</a>'
                    . '<span class="sf-sub">' . $e((string) $r['video_id']) . '</span>';
            },
            'channel' => function (array $row) use ($e): string {
                return $e((string) ($row['_row']['channel_title'] ?? ''));
            },
            'type' => function (array $row) use ($e): string {
                return '<span class="sf-pill">' . $e((string) $row['_row']['media_type']) . '</span>';
            },
            'status' => function (array $row) use ($e): string {
                $status = (string) $row['_row']['status'];
                $cls = match ($status) {
                    'public' => 'is-on',
                    'processing' => 'is-wait',
                    default => 'is-off',
                };
                return '<span class="sf-pill ' . $cls . '">' . $e(ucfirst($status)) . '</span>';
            },
            'views_count' => function (array $row): string {
                return number_format((int) $row['_row']['views_count']);
            },
            'created_at' => function (array $row) use ($e): string {
                $ts = strtotime((string) $row['_row']['created_at']);
                return $e($ts ? date('M j, Y', $ts) : '');
            },
            'actions' => function (array $row) use ($e): string {
                $r = $row['_row'];
                return '<a class="sf-btn is-sm" href="'
                    . $e($this->url->to('media/studio/edit', [
                        'id' => (string) $r['video_id'],
                        'channel_id' => (int) $r['channel_id'],
                    ]))
                    . '">Edit</a>';
            },
        ]);

        $this->view->assign('title', 'Media videos');
        echo $this->view->inline(static function () use ($dataset): void {
            echo '<div class="sf-page"><div class="sf-head"><div><h1>Videos</h1>'
               . '<p>All uploaded and linked media items.</p></div></div>';
            $dataset->render();
            echo '</div>';
        }, 'admin', ['title' => 'Media videos']);
    }
}
