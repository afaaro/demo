<?php
declare(strict_types=1);
/** @var array<string,mixed> $video */
/** @var array{likes:int,dislikes:int} $reactions */
/** @var string|null $userReaction */
/** @var list<array<string,mixed>> $comments */
/** @var list<array<string,mixed>> $recommendations */
/** @var string $csrf */
/** @var int $userId */

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$id = (string) $video['video_id'];
$type = (string) $video['media_type'];
$thumb = media_asset_url($video['thumbnail_path'] ?? null);
$file = media_asset_url($video['file_path'] ?? null);
$source = (string) ($video['source_url'] ?? '');
$embed = ($type === 'embed' || ($source !== '' && media_parse_external_url($source)))
    ? media_embed_html($video)
    : '';
?>
<div class="media-shell">
  <div class="media-watch">
    <div>
      <?php if ($embed !== ''): ?>
        <div class="media-player"><?= $embed ?></div>
      <?php elseif ($type === 'audio' || ($file !== '' && str_contains((string) ($video['file_path'] ?? ''), '/audio/'))): ?>
        <div class="media-player is-audio"<?= $thumb !== '' ? ' style="background-image:linear-gradient(rgba(11,18,32,.75),rgba(11,18,32,.9)),url(' . $e($thumb) . ');background-size:cover;background-position:center"' : '' ?>>
          <audio controls controlsList="nodownload" src="<?= $e($file ?: $source) ?>"></audio>
        </div>
      <?php elseif ($type === 'image' || ($file !== '' && str_contains((string) ($video['file_path'] ?? ''), '/images/'))): ?>
        <div class="media-player">
          <img src="<?= $e($file ?: $source) ?>" alt="<?= $e((string) $video['title']) ?>">
        </div>
      <?php else: ?>
        <div class="media-player">
          <video controls playsinline controlsList="nodownload"
                 <?= $thumb !== '' ? 'poster="' . $e($thumb) . '"' : '' ?>
                 src="<?= $e($file ?: $source) ?>"></video>
        </div>
      <?php endif; ?>

      <h1 class="media-title"><?= $e((string) $video['title']) ?></h1>

      <div class="media-watch-bar">
        <a class="media-channel-row" href="<?= $e(url('media/channel', ['id' => (int) $video['channel_id']])) ?>" style="text-decoration:none;color:inherit">
          <?php $avatar = media_asset_url($video['channel_avatar'] ?? null); ?>
          <?php if ($avatar !== ''): ?>
            <img class="media-avatar" src="<?= $e($avatar) ?>" alt="">
          <?php else: ?>
            <span class="media-avatar">▣</span>
          <?php endif; ?>
          <div>
            <strong><?= $e((string) $video['channel_title']) ?></strong>
            <div class="media-meta">
              <span id="media-views" data-count="<?= (int) $video['views_count'] ?>"><?= number_format((int) $video['views_count']) ?></span> views
              · <?= $e(media_relative_time($video['created_at'] ?? null)) ?>
            </div>
          </div>
        </a>

        <div class="media-react">
          <button type="button" data-media-react="like" class="<?= $userReaction === 'like' ? 'is-on' : '' ?>"
                  onclick='mediaSendReaction(<?= json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "like")'>
            Like <span id="media-likes"><?= (int) $reactions['likes'] ?></span>
          </button>
          <button type="button" data-media-react="dislike" class="<?= $userReaction === 'dislike' ? 'is-on' : '' ?>"
                  onclick='mediaSendReaction(<?= json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "dislike")'>
            Dislike <span id="media-dislikes"><?= (int) $reactions['dislikes'] ?></span>
          </button>
        </div>
      </div>

      <?php if (!empty($video['description'])): ?>
        <div class="media-desc"><?= nl2br($e((string) $video['description'])) ?></div>
      <?php endif; ?>

      <section class="media-comments">
        <h2 style="font-size:1.05rem;margin:0 0 .75rem">Comments</h2>
        <?php if ($userId > 0): ?>
          <textarea id="media-comment-input" placeholder="Add a comment…"></textarea>
          <button type="button" class="media-btn is-sm" onclick='mediaPostComment(<?= json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Post</button>
        <?php else: ?>
          <p class="media-meta"><a href="<?= $e(url('user/login', ['next' => 'media/watch', 'id' => $id])) ?>">Sign in</a> to comment.</p>
        <?php endif; ?>

        <div id="media-comments-list" style="margin-top:1rem">
          <?php if ($comments === []): ?>
            <p id="media-comments-empty" class="media-meta">No comments yet.</p>
          <?php else: ?>
            <?php foreach ($comments as $com): ?>
              <div class="media-comment">
                <time><?= $e(media_relative_time($com['created_at'] ?? null)) ?></time>
                <strong><?= $e((string) ($com['username'] ?? 'User')) ?></strong>
                <p><?= nl2br($e((string) $com['comment_text'])) ?></p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <aside>
      <h2 style="font-size:.92rem;text-transform:uppercase;letter-spacing:.04em;color:var(--media-muted);margin:0 0 .75rem">Up next</h2>
      <?php if ($recommendations === []): ?>
        <p class="media-meta">Nothing else yet.</p>
      <?php else: ?>
        <?php foreach ($recommendations as $rec): ?>
          <?php $rThumb = media_asset_url($rec['thumbnail_path'] ?? null); ?>
          <a class="media-side-item" href="<?= $e(url('media/watch', ['id' => (string) $rec['video_id']])) ?>">
            <div class="media-thumb">
              <?php if ($rThumb !== ''): ?>
                <img src="<?= $e($rThumb) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="media-thumb-fallback">▶</div>
              <?php endif; ?>
            </div>
            <div>
              <h4><?= $e((string) $rec['title']) ?></h4>
              <div class="media-meta"><?= $e((string) ($rec['channel_title'] ?? '')) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </aside>
  </div>
</div>
<script>
window.MEDIA_CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MEDIA_VIDEO_ID = <?= json_encode($id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MEDIA_URLS = {
  view: <?= json_encode(url('media/interact/view'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  reaction: <?= json_encode(url('media/interact/reaction'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  comment: <?= json_encode(url('media/interact/comment'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
