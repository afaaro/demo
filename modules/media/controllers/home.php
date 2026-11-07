<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaHome extends Controller
{
    public function indexAction(): void
    {
        $videos = new VideoRepository($this->db);
        $format = (string) $this->request->get('format', 'string', 'all');
        if (!in_array($format, ['all', 'video', 'audio', 'images'], true)) {
            $format = 'all';
        }
        $q = trim((string) $this->request->get('q', 'string', ''));
        $feed = $videos->feed($format, $q, 24);

        $this->bootAssets('Media');
        echo $this->view->inline(function () use ($feed, $format, $q): void {
            include get_module_path('media') . '/views/feed.php';
        }, 'main', ['title' => 'Media']);
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
