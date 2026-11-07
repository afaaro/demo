<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\ChannelRepository;
use Module\Media\Services\MediaStorage;
use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaUpload extends Controller
{
    private ChannelRepository $channels;
    private VideoRepository $videos;
    private MediaStorage $storage;

    protected function boot(): void
    {
        media_require_login('media/studio');
        $this->channels = new ChannelRepository($this->db);
        $this->videos = new VideoRepository($this->db);
        $this->storage = new MediaStorage();
    }

    public function indexAction(): void
    {
        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $userId = (int) $this->auth->id();

        if (!$this->channels->canManage($channelId, $userId)) {
            $this->notify->error('You cannot upload to this channel.');
            redirect_to('media/studio');
        }

        $csrf = $this->request->csrfToken();
        $chunkUrl = url('media/upload/chunk');
        $saveUrl = url('media/upload/save');
        $linkUrl = url('media/upload/link');
        $studioUrl = url('media/studio', ['channel_id' => $channelId]);

        $this->view->assign('title', 'Upload');
        $this->view->setTitle('Upload');
        $this->view->addModuleStyle('media', 'media.css');
        $this->view->addModuleScript('media', 'media.js');
        $src = $this->view->e($this->view->moduleAsset('media', 'media.js'));
        $this->view->block('scripts', '<script src="' . $src . '" defer></script>');

        echo $this->view->inline(function () use ($channelId, $csrf, $chunkUrl, $saveUrl, $linkUrl, $studioUrl): void {
            include get_module_path('media') . '/views/studio/upload.php';
        }, 'main', ['title' => 'Upload']);
    }

    public function chunkAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $channelId = (int) $this->request->get('channel_id', 'int', 0);
        $userId = (int) $this->auth->id();
        if (!$this->channels->canManage($channelId, $userId)) {
            echo json_encode(['error' => 'Unauthorized.']);
            return;
        }

        $uuid = (string) $this->request->get('uuid', 'string', '');
        $chunkIndex = (int) $this->request->get('index', 'int', -1);
        $totalChunks = (int) $this->request->get('total', 'int', 0);
        $fileName = (string) $this->request->get('filename', 'string', '');

        $maxChunks = 2000; // 2000 x ~1MB ≈ 2GB
        if ($totalChunks < 1 || $totalChunks > $maxChunks || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            echo json_encode(['error' => 'Invalid chunk parameters.']);
            return;
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, media_allowed_extensions(), true)) {
            echo json_encode(['error' => 'This file type is not allowed.']);
            return;
        }

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            echo json_encode(['error' => 'Missing chunk data.']);
            return;
        }

        // Keep each part under PHP's upload_max_filesize (often 2M).
        if ((int) $_FILES['file']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['error' => 'Chunk too large for server limits.']);
            return;
        }

        try {
            $this->storage->saveChunk($uuid, $chunkIndex, $_FILES['file']['tmp_name']);
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        if ($this->storage->countChunks($uuid) === $totalChunks) {
            try {
                $assembled = $this->storage->assembleChunks($uuid, $fileName, $totalChunks);
                echo json_encode([
                    'success'    => true,
                    'completed'  => true,
                    'filepath'   => $assembled['relative'],
                    'media_type' => $assembled['media_type'],
                ]);
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }

        echo json_encode([
            'success'   => true,
            'completed' => false,
            'message'   => "Chunk {$chunkIndex} saved.",
        ]);
    }

    public function saveAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $channelId = (int) ($this->request->post('channel_id', 'int', 0) ?: ($_POST['channel_id'] ?? 0));
        $userId = (int) $this->auth->id();
        $title = trim((string) ($_POST['title'] ?? ''));
        $filePath = trim((string) ($_POST['file_path'] ?? ''));
        $mediaType = (string) ($_POST['media_type'] ?? 'video');

        if (!$this->channels->canManage($channelId, $userId) || $title === '' || $filePath === '') {
            echo json_encode(['error' => 'Missing values or unauthorized.']);
            return;
        }

        if (!in_array($mediaType, ['video', 'audio', 'image'], true)) {
            $mediaType = media_type_from_extension(pathinfo($filePath, PATHINFO_EXTENSION));
        }

        if (!$this->storage->isRegisteredUploadPath($filePath)) {
            echo json_encode(['error' => 'Invalid file reference.']);
            return;
        }

        $exists = $this->db->query(
            'SELECT 1 FROM `#__media_videos` WHERE `file_path` = ? LIMIT 1',
            [$filePath]
        )->row;
        if ($exists !== []) {
            echo json_encode(['error' => 'This file is already registered.']);
            return;
        }

        $thumbnailPath = null;
        if (!empty($_FILES['thumbnail']['tmp_name']) && is_uploaded_file($_FILES['thumbnail']['tmp_name'])) {
            $basename = pathinfo($filePath, PATHINFO_FILENAME);
            $thumbnailPath = $this->storage->saveThumbnailJpeg($_FILES['thumbnail']['tmp_name'], $basename);
        }

        $id = $this->videos->create([
            'channel_id'     => $channelId,
            'uploaded_by'    => $userId,
            'title'          => $title,
            'media_type'     => $mediaType,
            'file_path'      => $filePath,
            'thumbnail_path' => $thumbnailPath,
            'status'         => 'public',
        ]);

        echo json_encode(['success' => true, 'video_id' => $id]);
    }

    public function linkAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $channelId = (int) $this->request->post('channel_id', 'int', 0);
        $userId = (int) $this->auth->id();
        $title = trim((string) $this->request->post('title', 'string', ''));
        $url = trim((string) $this->request->post('url', 'string', ''));
        $description = trim((string) $this->request->post('description', 'string', ''));

        if (!$this->channels->canManage($channelId, $userId)) {
            echo json_encode(['error' => 'Unauthorized.']);
            return;
        }

        $parsed = media_parse_external_url($url);
        if ($parsed === null) {
            echo json_encode(['error' => 'Unsupported URL. Use YouTube, Vimeo, or a direct media link.']);
            return;
        }

        if ($title === '') {
            $title = $parsed['provider'] === 'youtube'
                ? 'YouTube video'
                : ($parsed['provider'] === 'vimeo' ? 'Vimeo video' : 'Linked media');
        }

        $id = $this->videos->create([
            'channel_id'     => $channelId,
            'uploaded_by'    => $userId,
            'title'          => $title,
            'description'    => $description,
            'media_type'     => $parsed['media_type'],
            'source_url'     => $parsed['source_url'],
            'thumbnail_path' => $parsed['thumbnail'],
            'status'         => 'public',
        ]);

        echo json_encode(['success' => true, 'video_id' => $id]);
    }
}
