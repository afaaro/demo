<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Media\Services\ChannelRepository;
use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaAdminHome extends Controller
{
    protected function boot(): void
    {
        media_require_admin();
    }

    public function indexAction(): void
    {
        $channels = new ChannelRepository($this->db);
        $videos = new VideoRepository($this->db);

        $stats = [
            'channels' => $channels->countAll(),
            'videos'   => $videos->countAll(),
            'public'   => $videos->countPublic(),
            'views'    => $videos->totalViews(),
        ];

        $latest = $this->db->query(
            "SELECT v.video_id, v.title, v.status, v.media_type, v.views_count, v.created_at,
                    c.title AS channel_title
             FROM `#__media_videos` v
             INNER JOIN `#__media_channels` c ON c.channel_id = v.channel_id
             ORDER BY v.created_at DESC
             LIMIT 8"
        )->rows;

        $this->view->assign('title', 'Media');
        echo $this->view->inline(function () use ($stats, $latest): void {
            include get_module_path('media') . '/views/admin/home.php';
        }, 'admin', ['title' => 'Media']);
    }
}
