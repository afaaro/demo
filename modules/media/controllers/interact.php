<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Media\Services\VideoRepository;
use System\Engine\Controller;

class MediaInteract extends Controller
{
    public function viewAction(): void
    {
        $this->jsonHeader();
        $videoId = media_normalize_video_id($this->request->get('id', 'string', ''));
        if ($videoId === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
            return;
        }

        $counted = $this->shouldTrackOnce('media_video', $videoId);
        if ($counted) {
            $this->db->query(
                'UPDATE `#__media_videos` SET `views_count` = `views_count` + 1 WHERE `video_id` = ?',
                [$videoId]
            );
        }

        echo json_encode(['success' => true, 'counted' => $counted]);
    }

    public function reactionAction(): void
    {
        $this->jsonHeader();

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['success' => false, 'error' => 'Invalid request.']);
            return;
        }

        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1) {
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            return;
        }

        $videoId = media_normalize_video_id($this->request->post('video_id', 'string', ''));
        $type = (string) $this->request->post('type', 'string', '');
        if ($videoId === '' || !in_array($type, ['like', 'dislike'], true)) {
            echo json_encode(['success' => false, 'error' => 'Malformed payload.']);
            return;
        }

        $videos = new VideoRepository($this->db);
        if ($videos->findPublic($videoId) === null) {
            echo json_encode(['success' => false, 'error' => 'Video not found.']);
            return;
        }

        $existing = $this->db->query(
            'SELECT `reaction_id`, `type` FROM `#__media_video_reactions`
             WHERE `video_id` = ? AND `user_id` = ? LIMIT 1',
            [$videoId, $userId]
        )->row;

        if ($existing !== []) {
            if ((string) $existing['type'] === $type) {
                $this->db->query(
                    'DELETE FROM `#__media_video_reactions` WHERE `reaction_id` = ?',
                    [(int) $existing['reaction_id']]
                );
            } else {
                $this->db->query(
                    'UPDATE `#__media_video_reactions` SET `type` = ?, `created_at` = ? WHERE `reaction_id` = ?',
                    [$type, date('Y-m-d H:i:s'), (int) $existing['reaction_id']]
                );
            }
        } else {
            $this->db->query(
                'INSERT INTO `#__media_video_reactions` (`video_id`, `user_id`, `type`, `created_at`)
                 VALUES (?, ?, ?, ?)',
                [$videoId, $userId, $type, date('Y-m-d H:i:s')]
            );
        }

        $counts = $videos->reactionCounts($videoId);
        echo json_encode([
            'success'        => true,
            'likes_count'    => $counts['likes'],
            'dislikes_count' => $counts['dislikes'],
            'user_reaction'  => $videos->userReaction($videoId, $userId),
        ]);
    }

    public function commentAction(): void
    {
        $this->jsonHeader();

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['success' => false, 'error' => 'Invalid request.']);
            return;
        }

        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId < 1) {
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            return;
        }

        $videoId = media_normalize_video_id($this->request->post('video_id', 'string', ''));
        $text = trim((string) $this->request->post('comment_text', 'string', ''));
        if ($videoId === '' || $text === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
            return;
        }
        if (mb_strlen($text) > 2000) {
            echo json_encode(['success' => false, 'error' => 'Comment is too long.']);
            return;
        }

        $videos = new VideoRepository($this->db);
        if ($videos->findPublic($videoId) === null) {
            echo json_encode(['success' => false, 'error' => 'Video not found.']);
            return;
        }

        $this->db->query(
            'INSERT INTO `#__media_video_comments` (`video_id`, `user_id`, `comment_text`, `created_at`)
             VALUES (?, ?, ?, ?)',
            [$videoId, $userId, $text, date('Y-m-d H:i:s')]
        );

        $label = trim((string) ($this->auth->displayName ?: $this->auth->username));
        if ($label === '') {
            $label = 'User';
        }

        echo json_encode([
            'success'  => true,
            'username' => $label,
            'comment'  => $text,
            'date'     => 'Just now',
        ]);
    }

    private function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    private function shouldTrackOnce(string $scope, string $contentId): bool
    {
        if ($contentId === '') {
            return false;
        }

        $key = 'view_tracking.' . $scope;
        $views = $this->session->get($key, []);
        if (!is_array($views)) {
            $views = [];
        }

        $now = time();
        foreach ($views as $id => $seenAt) {
            if (!is_numeric($seenAt) || (int) $seenAt <= $now - 1800) {
                unset($views[$id]);
            }
        }

        if (isset($views[$contentId])) {
            $this->session->set($key, $views);
            return false;
        }

        $views[$contentId] = $now;
        if (count($views) > 200) {
            asort($views, SORT_NUMERIC);
            $views = array_slice($views, -200, null, true);
        }

        $this->session->set($key, $views);
        return true;
    }
}
