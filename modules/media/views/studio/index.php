<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $accessible */
/** @var array<string,mixed> $active */
/** @var int $activeId */
/** @var list<array<string,mixed>> $items */
/** @var int $page */
/** @var int $totalPages */
/** @var array{videos:int,staff:int,views:int} $metrics */

$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$canDelete = in_array((string) ($active['role'] ?? ''), ['owner', 'manager'], true);
?>
<div class="media-shell">
  <div class="media-studio-head">
    <div>
      <h1 style="margin:0 0 .25rem">Creator Studio</h1>
      <p class="media-meta" style="margin:0">
        Managing <strong><?= $e((string) $active['title']) ?></strong>
        · role <?= $e((string) ($active['role'] ?? '')) ?>
      </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <?php if (count($accessible) > 1): ?>
        <select onchange="location=this.value" style="border:1px solid var(--media-line);border-radius:999px;padding:.45rem .8rem">
          <?php foreach ($accessible as $ch): ?>
            <option value="<?= $e(url('media/studio', ['channel_id' => (int) $ch['channel_id']])) ?>"
              <?= (int) $ch['channel_id'] === $activeId ? 'selected' : '' ?>>
              <?= $e((string) $ch['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <a class="media-btn is-ghost" href="<?= $e(url('media/studio/create')) ?>">New channel</a>
      <a class="media-btn is-accent" href="<?= $e(url('media/upload', ['channel_id' => $activeId])) ?>">Upload</a>
    </div>
  </div>

  <div class="media-stats">
    <div class="media-stat"><span>Items</span><strong><?= (int) $metrics['videos'] ?></strong></div>
    <div class="media-stat"><span>Views</span><strong><?= number_format((int) $metrics['views']) ?></strong></div>
    <div class="media-stat"><span>Staff</span><strong><?= (int) $metrics['staff'] ?></strong></div>
  </div>

  <?php if ($items === []): ?>
    <div class="media-empty">No media in this channel yet. Upload a file or paste a YouTube link.</div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="media-table">
        <thead>
          <tr>
            <th>Media</th>
            <th>Type</th>
            <th>Status</th>
            <th>Views</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $video): ?>
            <?php $thumb = media_asset_url($video['thumbnail_path'] ?? null); ?>
            <tr>
              <td>
                <div style="display:flex;gap:.75rem;align-items:center">
                  <div class="media-thumb" style="width:72px;flex:0 0 72px;margin:0;border-radius:8px">
                    <?php if ($thumb !== ''): ?>
                      <img src="<?= $e($thumb) ?>" alt="">
                    <?php else: ?>
                      <div class="media-thumb-fallback" style="font-size:1rem">▶</div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <strong><?= $e((string) $video['title']) ?></strong>
                  </div>
                </div>
              </td>
              <td><?= $e((string) $video['media_type']) ?></td>
              <td><?= $e(ucfirst((string) $video['status'])) ?></td>
              <td><?= number_format((int) $video['views_count']) ?></td>
              <td><?= $e(media_relative_time($video['created_at'] ?? null)) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/watch', ['id' => (string) $video['video_id']])) ?>">View</a>
                <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/studio/edit', ['id' => (string) $video['video_id'], 'channel_id' => $activeId])) ?>">Edit</a>
                <?php if ($canDelete): ?>
                  <form method="post" action="<?= $e(url('media/studio/delete', ['id' => (string) $video['video_id'], 'channel_id' => $activeId])) ?>" style="display:inline" onsubmit="return confirm('Delete this media permanently?')">
                    <?= $this->request->csrfField() ?>
                    <button class="media-btn is-ghost is-sm" type="submit" style="color:var(--media-accent)">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="media-pager">
        <?php if ($page > 1): ?>
          <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/studio', ['channel_id' => $activeId, 'page' => $page - 1])) ?>">Prev</a>
        <?php endif; ?>
        <span class="media-meta">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="media-btn is-ghost is-sm" href="<?= $e(url('media/studio', ['channel_id' => $activeId, 'page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
