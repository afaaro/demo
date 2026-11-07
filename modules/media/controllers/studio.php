<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\ChannelRepository;
use Module\Media\Services\MediaStorage;
use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaStudio extends Controller
{
    private ChannelRepository $channels;
    private VideoRepository $videos;

    protected function boot(): void
    {
        media_require_login('media/studio');
        $this->channels = new ChannelRepository($this->db);
        $this->videos = new VideoRepository($this->db);
    }

    public function indexAction(): void
    {
        $userId = (int) $this->auth->id();
        $accessible = $this->channels->forUser($userId);

        if ($accessible === []) {
            $this->bootAssets('Creator Studio');
            echo $this->view->inline(function (): void {
                include get_module_path('media') . '/views/studio/empty.php';
            }, 'main', ['title' => 'Creator Studio']);
            return;
        }

        $activeId = (int) $this->request->get('channel_id', 'int', 0);
        $active = null;
        foreach ($accessible as $ch) {
            if ((int) $ch['channel_id'] === $activeId) {
                $active = $ch;
                break;
            }
        }
        if ($active === null) {
            $active = $accessible[0];
            $activeId = (int) $active['channel_id'];
        }

        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $items = $this->videos->forChannel($activeId, false, $limit, $offset);
        $total = $this->videos->countForChannel($activeId, false);
        $totalPages = max(1, (int) ceil($total / $limit));

        $metrics = [
            'videos' => $total,
            'staff'  => (int) $this->db->query(
                'SELECT COUNT(*) FROM `#__media_channel_staff` WHERE `channel_id` = ?',
                [$activeId]
            )->value,
            'views'  => (int) $this->db->query(
                'SELECT COALESCE(SUM(`views_count`),0) FROM `#__media_videos` WHERE `channel_id` = ?',
                [$activeId]
            )->value,
        ];

        $this->bootAssets('Creator Studio');
        echo $this->view->inline(function () use ($accessible, $active, $activeId, $items, $page, $totalPages, $metrics): void {
            include get_module_path('media') . '/views/studio/index.php';
        }, 'main', ['title' => 'Creator Studio']);
    }

    public function createAction(): void
    {
        $title = '';
        $description = '';

        if ($this->request->isPost()) {
            $this->request->enforceCsrf();
            $title = trim((string) $this->request->post('title', 'string', ''));
            $description = trim((string) $this->request->post('description', 'string', ''));

            if ($title === '') {
                $this->notify->error('Channel title is required.');
            } else {
                $id = $this->channels->create((int) $this->auth->id(), $title, $description);
                $this->notify->success('Channel created.');
                redirect_to('media/studio', ['channel_id' => $id]);
            }
        }

        $this->bootAssets('Create channel');
        echo $this->view->inline(function () use ($title, $description): void {
            include get_module_path('media') . '/views/studio/create_channel.php';
        }, 'main', ['title' => 'Create channel']);
    }

    public function editAction(): void
    {
        $videoId = media_normalize_video_id($this->request->get('id', 'string', ''));
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $userId = (int) $this->auth->id();

        if (!$this->channels->canManage($channelId, $userId)) {
            $this->notify->error('You cannot manage this channel.');
            redirect_to('media/studio');
        }

        $video = $videoId !== '' ? $this->videos->find($videoId) : null;
        if ($video === null || (int) $video['channel_id'] !== $channelId) {
            $this->notify->error('Media item not found.');
            redirect_to('media/studio', ['channel_id' => $channelId]);
        }

        if ($this->request->isPost()) {
            $this->request->enforceCsrf();
            $title = trim((string) $this->request->post('title', 'string', ''));
            $status = (string) $this->request->post('status', 'string', 'private');
            $description = trim((string) $this->request->post('description', 'string', ''));

            if (!in_array($status, ['public', 'private', 'processing'], true)) {
                $status = 'private';
            }
            if ($title === '') {
                $this->notify->error('Title is required.');
            } else {
                $this->videos->updateMeta($videoId, $channelId, $title, $status, $description);
                $this->notify->success('Saved.');
                redirect_to('media/studio', ['channel_id' => $channelId]);
            }
            $video['title'] = $title;
            $video['status'] = $status;
            $video['description'] = $description;
        }

        $this->bootAssets('Edit media');
        echo $this->view->inline(function () use ($video, $channelId): void {
            include get_module_path('media') . '/views/studio/edit.php';
        }, 'main', ['title' => 'Edit media']);
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            $this->notify->error('Invalid request.');
            redirect_to('media/studio');
        }

        $videoId = media_normalize_video_id($this->request->get('id', 'string', ''));
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $userId = (int) $this->auth->id();

        if (!$this->channels->canDelete($channelId, $userId)) {
            $this->notify->error('You need owner or manager access to delete.');
            redirect_to('media/studio', ['channel_id' => $channelId]);
        }

        $deleted = $videoId !== '' ? $this->videos->delete($videoId, $channelId) : null;
        if ($deleted !== null) {
            $storage = new MediaStorage();
            $storage->deleteRelative($deleted['file_path'] ?? null);
            $storage->deleteRelative($deleted['thumbnail_path'] ?? null);
            $this->notify->success('Media deleted.');
        } else {
            $this->notify->error('Media item not found.');
        }

        redirect_to('media/studio', ['channel_id' => $channelId]);
    }

    private function bootAssets(string $title): void
    {
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('media', 'media.css');
    }
}
