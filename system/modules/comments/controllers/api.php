<?php
declare(strict_types=1);

use Module\Comments\Services\CommentRepository;
use System\Engine\Controller;

class CommentsApi extends Controller
{
    public function listAction(): void
    {
        $this->jsonHeader();
        $module = trim((string) $this->request->get('module', 'string', ''));
        $entityType = trim((string) $this->request->get('entity_type', 'string', ''));
        $entityId = trim((string) $this->request->get('entity_id', 'string', ''));
        $limit = (int) $this->request->get('limit', 'int', 50);

        $repo = new CommentRepository($this->db);
        $rows = $repo->forEntity($module, $entityType, $entityId, $limit, true);

        require_once get_module_path('comments') . '/helpers/functions.php';

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'username' => (string) ($row['username'] ?? 'User'),
                'body'     => (string) $row['body'],
                'when'     => comments_relative_time($row['created_at'] ?? null),
            ];
        }

        echo json_encode(['success' => true, 'comments' => $out]);
    }

    public function postAction(): void
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

        if (!$this->allowPost($userId)) {
            echo json_encode(['success' => false, 'error' => 'Please wait a moment before posting again.']);
            return;
        }

        $module = trim((string) $this->request->post('module', 'string', ''));
        $entityType = trim((string) $this->request->post('entity_type', 'string', ''));
        $entityId = trim((string) $this->request->post('entity_id', 'string', ''));
        $body = trim((string) $this->request->post('body', 'string', ''));

        if ($body === '') {
            echo json_encode(['success' => false, 'error' => 'Comment cannot be empty.']);
            return;
        }
        if (mb_strlen($body) > 2000) {
            echo json_encode(['success' => false, 'error' => 'Comment is too long.']);
            return;
        }

        $repo = new CommentRepository($this->db);
        if (!$repo->entityExists($module, $entityType, $entityId)) {
            echo json_encode(['success' => false, 'error' => 'Content not found.']);
            return;
        }

        try {
            $row = $repo->create($module, $entityType, $entityId, $userId, $body);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Could not save comment.']);
            return;
        }

        $this->markPosted($userId);

        $label = trim((string) ($row['username'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($this->auth->displayName ?: $this->auth->username));
        }
        if ($label === '') {
            $label = 'User';
        }

        echo json_encode([
            'success'  => true,
            'id'       => (int) ($row['id'] ?? 0),
            'username' => $label,
            'body'     => (string) ($row['body'] ?? $body),
            'when'     => 'Just now',
        ]);
    }

    private function allowPost(int $userId): bool
    {
        $key = '_comments_last_post_' . $userId;
        $last = (int) $this->session->get($key, 0);
        return (time() - $last) >= 20;
    }

    private function markPosted(int $userId): void
    {
        $this->session->set('_comments_last_post_' . $userId, time());
    }

    private function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }
}
