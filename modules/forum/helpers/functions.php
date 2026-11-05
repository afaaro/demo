<?php
declare(strict_types=1);

use System\Engine\ForbiddenException;
use System\Library\Database;
use System\Library\HtmlCleaner;
use System\Library\Paginator;
use System\Library\Request;
use System\Services\User;

function forum_validate_post(string $title, string $content): ?string
{
    $title   = trim($title);
    $content = trim($content);

    if ($title === '') {
        return 'Title is required.';
    }
    if (mb_strlen($title) < 3) {
        return 'Title must be at least 3 characters.';
    }
    if (mb_strlen($title) > 255) {
        return 'Title is too long.';
    }
    return forum_validate_reply($content);
}

function forum_validate_reply(string $content): ?string
{
    $content = trim($content);
    if ($content === '') {
        return 'Content is required.';
    }
    if (mb_strlen($content) < 2) {
        return 'Post is too short.';
    }
    if (mb_strlen($content) > 20000) {
        return 'Post is too long.';
    }
    return null;
}

function forum_avatar(string $name, string $size = 'm'): string
{
    $name = trim($name);
    $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    $hue = (abs(crc32(mb_strtolower($name))) % 48) * 7.5;
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<span class="xf-av xf-av--' . $e($size) . '" style="--xf-av-h:' . $hue . '" aria-hidden="true">'
        . $e($initial) . '</span>';
}

function forum_count(int $n): string
{
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string) $n;
}

function forum_new_url(string $categorySlug = ''): string
{
    $url = registry('url');
    if ($categorySlug !== '') {
        return $url->to('forum/new', ['category_slug' => $categorySlug]);
    }
    return $url->to('forum/new', [], false, false, false);
}

function forum_can_view_category(array $category): bool
{
    return User::canAccessCurrent((int) ($category['access_role'] ?? 0));
}

/**
 * @return array{sql: string, params: list<mixed>}
 */
function forum_access_clause(string $column = 'c.access_role'): array
{
    return User::accessSql($column);
}

function forum_can_post(array $category): bool
{
    if (!User::check()) {
        return false;
    }
    $status = (string) ($category['status'] ?? '');
    if ($status === 'locked' && !forum_can_moderate()) {
        return false;
    }
    if ($status === 'hidden' && !forum_can_moderate()) {
        return false;
    }
    return User::canAccessCurrent((int) ($category['post_role'] ?? 0));
}

function forum_can_moderate(): bool
{
    $user = User::current();
    if ($user === null) {
        return false;
    }
    return $user->can('forum.moderate')
        || $user->can('forum.admin.*')
        || $user->can('forum.admin.threads.index')
        || $user->can('forum.admin.posts.index');
}

function forum_require_moderate(): void
{
    if (!User::check()) {
        redirect_to('user/login');
    }
    if (!forum_can_moderate()) {
        throw new ForbiddenException('Forum moderation required.');
    }
}

/** @return list<int> */
function forum_posted_ids(Request $request): array
{
    $raw = $request->post('ids', 'raw', []);
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function forum_recount_category(Database $db, int $categoryId): void
{
    if ($categoryId <= 0) {
        return;
    }
    $db->query(
        "UPDATE `#__forum_categories` c
            SET `threads_count` = (
                    SELECT COUNT(*) FROM `#__forum_threads` t
                     WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0
                ),
                `posts_count` = (
                    SELECT COALESCE(SUM(t.`post_count`), 0) FROM `#__forum_threads` t
                     WHERE t.`category_id` = c.`id` AND t.`is_deleted` = 0
                )
          WHERE c.`id` = ?",
        [$categoryId]
    );
}

function forum_refresh_thread_last(Database $db, int $threadId): int
{
    $live = (int) $db->query(
        "SELECT COUNT(*) FROM `#__forum_posts` WHERE `thread_id` = ? AND `is_deleted` = 0",
        [$threadId]
    )->value;
    $last = $db->query(
        "SELECT `id`, `user_id`, `created_at`
           FROM `#__forum_posts`
          WHERE `thread_id` = ? AND `is_deleted` = 0
          ORDER BY `created_at` DESC, `id` DESC
          LIMIT 1",
        [$threadId]
    )->row;

    $db->update('forum_threads', [
        'post_count'   => max(0, $live),
        'last_post_id' => $last === [] ? null : (int) $last['id'],
        'last_post_at' => $last === [] ? null : (string) $last['created_at'],
        'last_post_by' => $last === [] ? null : (int) $last['user_id'],
    ], ['id' => $threadId]);

    return $live;
}

function forum_soft_delete_thread(Database $db, int $threadId): bool
{
    $thread = $db->first('forum_threads', ['id' => $threadId]);
    if ($thread === [] || (int) $thread['is_deleted'] === 1) {
        return false;
    }
    $db->update('forum_threads', ['is_deleted' => 1], ['id' => $threadId]);
    forum_recount_category($db, (int) $thread['category_id']);
    return true;
}

function forum_restore_thread(Database $db, int $threadId): bool
{
    $thread = $db->first('forum_threads', ['id' => $threadId]);
    if ($thread === [] || (int) $thread['is_deleted'] === 0) {
        return false;
    }
    $db->update('forum_threads', ['is_deleted' => 0], ['id' => $threadId]);
    forum_refresh_thread_last($db, $threadId);
    forum_recount_category($db, (int) $thread['category_id']);
    return true;
}

function forum_set_thread_flag(Database $db, int $threadId, string $flag, int $value): bool
{
    if (!in_array($flag, ['is_pinned', 'is_locked'], true)) {
        return false;
    }
    $thread = $db->first('forum_threads', ['id' => $threadId]);
    if ($thread === []) {
        return false;
    }
    $db->update('forum_threads', [$flag => $value ? 1 : 0], ['id' => $threadId]);
    return true;
}

function forum_move_thread(Database $db, int $threadId, int $categoryId): bool
{
    $thread = $db->first('forum_threads', ['id' => $threadId]);
    $category = $db->first('forum_categories', ['id' => $categoryId]);
    if ($thread === [] || $category === []) {
        return false;
    }
    $from = (int) $thread['category_id'];
    if ($from === $categoryId) {
        return false;
    }
    $slug = forum_slugify((string) $thread['title'], $db, $categoryId, $threadId);
    $db->update('forum_threads', [
        'category_id' => $categoryId,
        'slug'        => $slug,
    ], ['id' => $threadId]);
    forum_recount_category($db, $from);
    forum_recount_category($db, $categoryId);
    return true;
}

function forum_soft_delete_post(Database $db, int $postId): bool
{
    $post = $db->query(
        "SELECT p.*, t.`category_id`, t.`is_deleted` AS thread_deleted
           FROM `#__forum_posts` p
           JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
          WHERE p.`id` = ? LIMIT 1",
        [$postId]
    )->row;
    if ($post === [] || (int) $post['is_deleted'] === 1) {
        return false;
    }

    $threadId = (int) $post['thread_id'];
    $db->update('forum_posts', ['is_deleted' => 1], ['id' => $postId]);
    $live = forum_refresh_thread_last($db, $threadId);
    if ($live === 0) {
        $db->update('forum_threads', ['is_deleted' => 1], ['id' => $threadId]);
    }
    forum_recount_category($db, (int) $post['category_id']);
    return true;
}

function forum_restore_post(Database $db, int $postId): bool
{
    $post = $db->query(
        "SELECT p.*, t.`category_id`, t.`is_deleted` AS thread_deleted
           FROM `#__forum_posts` p
           JOIN `#__forum_threads` t ON t.`id` = p.`thread_id`
          WHERE p.`id` = ? LIMIT 1",
        [$postId]
    )->row;
    if ($post === [] || (int) $post['is_deleted'] === 0) {
        return false;
    }

    $threadId = (int) $post['thread_id'];
    if ((int) $post['thread_deleted'] === 1) {
        $db->update('forum_threads', ['is_deleted' => 0], ['id' => $threadId]);
    }
    $db->update('forum_posts', ['is_deleted' => 0], ['id' => $postId]);
    forum_refresh_thread_last($db, $threadId);
    forum_recount_category($db, (int) $post['category_id']);
    return true;
}

/** @param array<string, scalar|null> $query */
function forum_admin_pager(Paginator $pager, string $route, array $query): string
{
    $url = registry('url');
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div class="sf-pager-bar">';
    $html .= '<span class="sf-pager-bar__sum">Showing ' . $e($pager->summary()) . '</span>';
    $html .= $pager->render(
        static fn(int $p): string => $url->to($route, array_filter($query + ['page' => $p > 1 ? $p : null])),
        ['theme' => 'bootstrap-sm', 'class' => 'mb-0']
    );
    return $html . '</div>';
}

function forum_admin_bulk_script(string $formId, string $boxClass, string $confirmDelete): string
{
    $formId = htmlspecialchars($formId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $boxClass = htmlspecialchars($boxClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $confirmDelete = htmlspecialchars($confirmDelete, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return <<<HTML
<script>
(function () {
    var form = document.getElementById('{$formId}');
    var all = document.getElementById('{$formId}-all');
    var boxes = document.querySelectorAll('.{$boxClass}');
    var action = document.getElementById('{$formId}-action');
    var extra = document.getElementById('{$formId}-extra');
    var apply = document.getElementById('{$formId}-apply');
    var count = document.getElementById('{$formId}-count');
    if (!form || !action || !apply) return;
    Array.prototype.forEach.call(boxes, function (b) { b.checked = false; });
    if (all) all.checked = false;
    action.value = '';
    function selected() { return Array.prototype.filter.call(boxes, function (b) { return b.checked; }); }
    function sync() {
        var n = selected().length;
        if (count) count.textContent = n + ' selected';
        apply.disabled = n === 0 || !action.value;
        if (all) {
            all.checked = boxes.length > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
        if (extra) extra.classList.toggle('d-none', action.value !== 'move');
    }
    if (all) all.addEventListener('change', function () {
        Array.prototype.forEach.call(boxes, function (b) { b.checked = all.checked; });
        sync();
    });
    Array.prototype.forEach.call(boxes, function (b) { b.addEventListener('change', sync); });
    action.addEventListener('change', sync);
    form.addEventListener('submit', function (e) {
        if (selected().length === 0 || !action.value) { e.preventDefault(); return; }
        if ((action.value === 'delete' || action.value === 'destroy') && !window.confirm('{$confirmDelete}')) {
            e.preventDefault();
        }
    });
    sync();
})();
</script>
HTML;
}

function forum_can_edit_post(array $post): bool
{
    if (forum_can_moderate()) {
        return true;
    }
    $uid = (int) (User::id() ?? 0);
    return $uid > 0 && $uid === (int) ($post['user_id'] ?? 0);
}

function forum_is_unread(array $thread): bool
{
    if (!User::check()) {
        return false;
    }
    $last = (string) ($thread['last_post_at'] ?? '');
    $read = (string) ($thread['last_read_at'] ?? '');
    if ($last === '') {
        return $read === '';
    }
    if ($read === '') {
        return true;
    }
    return strcmp($read, $last) < 0;
}

function forum_when(?string $at): string
{
    if ($at === null || trim($at) === '') {
        return '—';
    }
    $ts = strtotime($at);
    if ($ts === false) {
        return $at;
    }
    $diff = time() - $ts;
    if ($diff < 45) {
        return 'just now';
    }
    if ($diff < 3600) {
        return max(1, (int) floor($diff / 60)) . 'm ago';
    }
    if ($diff < 86400) {
        return max(1, (int) floor($diff / 3600)) . 'h ago';
    }
    if ($diff < 7 * 86400) {
        return max(1, (int) floor($diff / 86400)) . 'd ago';
    }
    return date('d M Y', $ts);
}

function forum_excerpt(string $text, int $len = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len - 1)) . '…';
}

function forum_quote_markdown(string $username, string $content): string
{
    $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];
    $out = '> **@' . str_replace(["\n", '*'], '', $username) . '** wrote:';
    foreach ($lines as $line) {
        $out .= "\n> " . $line;
    }
    return $out . "\n\n";
}

function forum_user_label(array $row, string $userKey = 'username', string $nameKey = 'display_name'): string
{
    $name = trim((string) ($row[$nameKey] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $user = trim((string) ($row[$userKey] ?? ''));
    return $user !== '' ? $user : '—';
}

function forum_slugify(string $title, Database $db, int $categoryId, int $ignoreId = 0): string
{
    $base = slug($title);
    if ($base === '') {
        $base = 'thread';
    }

    $slug = $base;
    $n    = 2;
    while (true) {
        $row = $db->query(
            "SELECT `id` FROM `#__forum_threads`
              WHERE `category_id` = ? AND `slug` = ? AND `id` <> ?
              LIMIT 1",
            [$categoryId, $slug, $ignoreId]
        )->row;
        if ($row === []) {
            return $slug;
        }
        $slug = $base . '-' . $n;
        $n++;
        if ($n > 200) {
            return $base . '-' . bin2hex(random_bytes(3));
        }
    }
}

function forum_category_slugify(string $name, Database $db, int $ignoreId = 0): string
{
    $base = slug($name);
    if ($base === '') {
        $base = 'category';
    }
    $slug = $base;
    $n = 2;
    while (true) {
        $row = $db->query(
            "SELECT `id` FROM `#__forum_categories` WHERE `slug` = ? AND `id` <> ? LIMIT 1",
            [$slug, $ignoreId]
        )->row;
        if ($row === []) {
            return $slug;
        }
        $slug = $base . '-' . $n;
        $n++;
    }
}

function forum_render_markdown(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = preg_replace(
        '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
        '<a href="$2" rel="nofollow noopener" target="_blank">$1</a>',
        $html
    ) ?? $html;
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html) ?? $html;
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html) ?? $html;

    $lines = explode("\n", $html);
    $out = '';
    $i = 0;
    $n = count($lines);

    while ($i < $n) {
        $line = $lines[$i];

        if (preg_match('/^&gt;\s?(.*)$/', $line, $m) === 1) {
            $buf = [];
            while ($i < $n && preg_match('/^&gt;\s?(.*)$/', $lines[$i], $m) === 1) {
                $buf[] = $m[1];
                $i++;
            }
            $inner = '';
            foreach ($buf as $quoted) {
                $inner .= trim($quoted) === '' ? '<br>' : '<p>' . $quoted . '</p>';
            }
            $out .= '<blockquote>' . $inner . '</blockquote>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $line) === 1) {
            $out .= '<ul>';
            while ($i < $n && preg_match('/^[-*]\s+(.+)$/', $lines[$i], $m) === 1) {
                $out .= '<li>' . $m[1] . '</li>';
                $i++;
            }
            $out .= '</ul>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $line) === 1) {
            $out .= '<ol>';
            while ($i < $n && preg_match('/^\d+\.\s+(.+)$/', $lines[$i], $m) === 1) {
                $out .= '<li>' . $m[1] . '</li>';
                $i++;
            }
            $out .= '</ol>';
            continue;
        }

        if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m) === 1) {
            $out .= '<h3>' . $m[1] . '</h3>';
            $i++;
            continue;
        }

        if (preg_match('/^---+$/', trim($line)) === 1) {
            $out .= '<hr>';
            $i++;
            continue;
        }

        if (trim($line) === '') {
            $i++;
            continue;
        }

        $para = [];
        while ($i < $n && trim($lines[$i]) !== ''
            && preg_match('/^(&gt;|[-*]\s|\d+\.\s|#{1,3}\s|---+)/', $lines[$i]) !== 1
        ) {
            $para[] = $lines[$i];
            $i++;
        }
        $out .= '<p>' . implode('<br>', $para) . '</p>';
    }

    if (method_exists(HtmlCleaner::class, 'sanitize')) {
        return HtmlCleaner::sanitize($out);
    }
    return HtmlCleaner::clean($out);
}
