<?php
declare(strict_types=1);
/** @var array<string,mixed> $post */
/** @var array<int,int> $likeCounts */
/** @var array<int,bool> $liked */
/** @var array<int,int> $commentCounts */
/** @var string $csrf */
/** @var int $userId */
$e = $e ?? static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$postId = (int) ($post['id'] ?? 0);
$authorId = (int) ($post['user_id'] ?? 0);
$authorName = (string) ($post['author_name'] ?? community_display_name($post));
$likes = (int) ($likeCounts[$postId] ?? 0);
$isLiked = !empty($liked[$postId]);
$comments = (int) (($commentCounts ?? [])[$postId] ?? 0);
$media = is_array($post['media'] ?? null) ? $post['media'] : [];
$visibility = (string) ($post['visibility'] ?? 'friends');
?>
<article class="cm-post" data-post-id="<?= $postId ?>">
  <header class="cm-post__head">
    <a class="cm-avatar" href="<?= $e(url('community/profile', ['u' => $authorId])) ?>">
      <?= $e(mb_strtoupper(mb_substr($authorName, 0, 1))) ?>
    </a>
    <div>
      <a class="cm-author" href="<?= $e(url('community/profile', ['u' => $authorId])) ?>">
        <?= $e($authorName) ?>
      </a>
      <div class="cm-meta">
        <a href="<?= $e(url('community/post', ['id' => $postId])) ?>">
          <?= $e(community_relative_time($post['created_at'] ?? null)) ?>
        </a>
        · <span class="cm-vis-tag is-<?= $e($visibility) ?>"><?= $e(ucfirst($visibility)) ?></span>
      </div>
    </div>
    <?php if ($authorId === (int) $userId): ?>
      <form method="post" action="<?= $e(url('community/post/delete')) ?>" class="cm-post__delete"
            onsubmit="return confirm('Delete this post?');">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $postId ?>">
        <button type="submit" title="Delete">×</button>
      </form>
    <?php endif; ?>
  </header>

  <?php if (trim((string) ($post['body'] ?? '')) !== ''): ?>
    <div class="cm-post__body"><?= nl2br($e((string) $post['body'])) ?></div>
  <?php endif; ?>

  <?php foreach ($media as $m): ?>
    <?php $src = community_asset_url((string) ($m['path'] ?? '')); ?>
    <?php if ($src !== ''): ?>
      <a class="cm-post__photo" href="<?= $e(url('community/post', ['id' => $postId])) ?>">
        <img src="<?= $e($src) ?>" alt="" loading="lazy">
      </a>
    <?php endif; ?>
  <?php endforeach; ?>

  <footer class="cm-post__actions">
    <button type="button"
            class="cm-react <?= $isLiked ? 'is-on' : '' ?>"
            data-community-react="<?= $postId ?>">
      <span data-like-label><?= $isLiked ? 'Liked' : 'Like' ?></span>
      <span data-like-count><?= $e((string) $likes) ?></span>
    </button>
    <a href="<?= $e(url('community/post', ['id' => $postId])) ?>">
      Comment<?= $comments > 0 ? ' ' . $e((string) $comments) : '' ?>
    </a>
  </footer>
</article>
