<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaWatch extends Controller
{
    public function indexAction(): void
    {
        $id = media_normalize_video_id($this->request->get('id', 'string', ''));
        $videos = new VideoRepository($this->db);
        $video = $id !== '' ? $videos->findPublic($id) : null;

        if ($video === null) {
            $this->notify->error('That media item was not found.');
            redirect_to('media');
        }

        $reactions = $videos->reactionCounts($id);
        $userId = (int) ($this->auth->id() ?? 0);
        $userReaction = $videos->userReaction($id, $userId);
        $recommendations = $videos->recommendations($id, 8);
        $csrf = $this->request->csrfToken();

        $this->bootAssets((string) $video['title']);
        echo $this->view->inline(function () use (
            $video,
            $reactions,
            $userReaction,
            $recommendations,
            $csrf
        ): void {
            include get_module_path('media') . '/views/watch.php';
        }, 'main', ['title' => (string) $video['title']]);
    }

    private function bootAssets(string $title): void
    {
        $this->view->assign('title', $title);
        $this->view->setTitle($title);
        $this->view->addModuleStyle('media', 'media.css');
        $this->view->addModuleScript('media', 'media.js');
        $src = $this->view->e($this->view->moduleAsset('media', 'media.js'));
        $this->view->block('scripts', '<script src="' . $src . '" defer></script>');
    }
}
