<?php

class MediaInteract extends Controller {
    public function __construct(System\Engine\Registry $registry) {
        parent::__construct($registry);
        // Set all responses from this controller to return JSON natively
        header('Content-Type: application/json');
    }

    /**
     * Increment Video View Counter Asynchronously
     * Route: /media/interact/view?id=VIDEO_ID
     */
    public function viewAction(): void {
        $videoId = $this->request->get('id', 'int');

        if (!empty($videoId)) {
            $counted = $this->shouldTrackOnce('media_video', $videoId);

            if ($counted) {
                // Keep the increment atomic while avoiding repeat counts in one session.
                $this->db->query("
                    UPDATE `#__media_videos`
                    SET `views_count` = `views_count` + 1
                    WHERE `video_id` = ?
                ", [$videoId]);
            }

            echo json_encode(['success' => true, 'counted' => $counted]);
            return;
        }

        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    }

    /**
     * Count one view per content item per session within a 30-minute window.
     * The map is pruned and capped to keep long-lived sessions small.
     */
    private function shouldTrackOnce(string $scope, int $contentId): bool
    {
        if ($contentId < 1) {
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

        $id = (string) $contentId;
        if (isset($views[$id])) {
            $this->session->set($key, $views);
            return false;
        }

        $views[$id] = $now;
        if (count($views) > 200) {
            asort($views, SORT_NUMERIC);
            $views = array_slice($views, -200, null, true);
        }

        $this->session->set($key, $views);
        return true;
    }

    /**
     * Toggle Video Reaction (Like/Dislike)
     * Route: /media/interact/reaction
     */
    public function reactionAction(): void {
        $currentUserId = $this->auth->user('id');
        
        if (empty($currentUserId)) {
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            return;
        }

        $videoId = $this->request->post('video_id', 'int');
        $reactionType = $this->request->post('type', 'string'); // 'like' or 'dislike'

        if (empty($videoId) || !in_array($reactionType, ['like', 'dislike'])) {
            echo json_encode(['success' => false, 'error' => 'Malformed dataset payload.']);
            return;
        }

        // Check if a reaction entry already exists for this user/video pair
        $existing = $this->db->query("
            SELECT `reaction_id`, `type` FROM `#__media_video_reactions` 
            WHERE `video_id` = ? AND `user_id` = ? LIMIT 1
        ", [$videoId, $currentUserId])->row;

        if (!empty($existing)) {
            if ($existing['type'] === $reactionType) {
                // If they click the exact same button again, withdraw the reaction entirely (Toggle Off)
                $this->db->query("DELETE FROM `#__media_video_reactions` WHERE `reaction_id` = ?", [$existing['reaction_id']]);
                $actionPerformed = 'removed';
            } else {
                // Flip preference from like to dislike, or vice-versa
                $this->db->query("UPDATE `#__media_video_reactions` SET `type` = ?, `created_at` = NOW() WHERE `reaction_id` = ?", [$reactionType, $existing['reaction_id']]);
                $actionPerformed = 'swapped';
            }
        } else {
            // Fresh reaction placement write
            $this->db->query("
                INSERT INTO `#__media_video_reactions` (`video_id`, `user_id`, `type`, `created_at`) 
                VALUES (?, ?, ?, NOW())
            ", [$videoId, $currentUserId, $reactionType]);
            $actionPerformed = 'added';
        }

        // Extract total metrics to update the user interface dynamically
        $likes = $this->db->query("SELECT COUNT(*) AS total FROM `#__media_video_reactions` WHERE `video_id` = ? AND `type` = 'like'", [$videoId])->row['total'] ?? 0;
        $dislikes = $this->db->query("SELECT COUNT(*) AS total FROM `#__media_video_reactions` WHERE `video_id` = ? AND `type` = 'dislike'", [$videoId])->row['total'] ?? 0;

        echo json_encode([
            'success' => true, 
            'action' => $actionPerformed,
            'likes_count' => $likes,
            'dislikes_count' => $dislikes
        ]);
    }

    /**
     * Submit Interactive Comment String
     * Route: /media/interact/comment
     */
    public function commentAction(): void {
        $currentUserId = $this->auth->user('id');
        $username = $this->auth->user('username');

        if (empty($currentUserId)) {
            echo json_encode(['success' => false, 'error' => 'Authentication required.']);
            return;
        }

        $videoId = $this->request->post('video_id', 'int');
        $commentText = trim($this->request->post('comment_text', 'string'));

        if (empty($videoId) || empty($commentText)) {
            echo json_encode(['success' => false, 'error' => 'Comment body context cannot be completely blank.']);
            return;
        }
        
        // Commit content node write to schema
        $this->db->query("
            INSERT INTO `#__media_video_comments` (`video_id`, `user_id`, `comment_text`, `created_at`) 
            VALUES (?, ?, ?, NOW())
        ", [$videoId, $currentUserId, $commentText]);

        echo json_encode([
            'success' => true,
            'username' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
            'comment' => htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8'),
            'date' => 'Just Now'
        ]);
    }
}