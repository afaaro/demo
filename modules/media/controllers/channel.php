<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\ChannelRepository;
use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaChannel extends Controller
{
    public function indexAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $channels = new ChannelRepository($this->db);
        $videos = new VideoRepository($this->db);

        $channel = $channels->find($id);
        if ($channel === null) {
            $this->notify->error('Channel not found.');
            redirect_to('media');
        }

        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $limit = 12;
        $offset = ($page - 1) * $limit;
        $items = $videos->forChannel($id, true, $limit, $offset);
        $total = $videos->countForChannel($id, true);
        $totalPages = max(1, (int) ceil($total / $limit));

        $this->bootAssets((string) $channel['title']);
        echo $this->view->inline(function () use ($channel, $items, $page, $totalPages, $total): void {
            include get_module_path('media') . '/views/channel.php';
        }, 'main', ['title' => (string) $channel['title']]);
    }

    private function bootAssets(string $title): void
    {
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('media', 'media.css');
    }
}
