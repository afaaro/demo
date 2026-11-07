<?php
declare(strict_types=1);
/** @var array{channels:int,videos:int,public:int,views:int} $stats */
/** @var list<array<string,mixed>> $latest */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="sf-page">
  <div class="sf-head">
    <div>
      <h1>Media</h1>
      <p>Channels, uploads, and embeds.</p>
    </div>
    <div class="sf-actions">
      <a class="sf-btn" href="<?= $e($this->url->to('media')) ?>" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right"></i>Open feed
      </a>
      <a class="sf-btn is-primary" href="<?= $e($this->url->to('media/studio')) ?>">
        <i class="bi bi-camera-reels"></i>Studio
      </a>
    </div>
  </div>

  <div class="sf-stats">
    <a class="sf-stat" href="<?= $e($this->url->to('media/admin/channels')) ?>">
      <span>Channels</span><strong><?= (int) $stats['channels'] ?></strong>
    </a>
    <a class="sf-stat" href="<?= $e($this->url->to('media/admin/videos')) ?>">
      <span>Videos</span><strong><?= (int) $stats['videos'] ?></strong>
    </a>
    <a class="sf-stat" href="<?= $e($this->url->to('media/admin/videos', ['status' => 'public'])) ?>">
      <span>Public</span><strong><?= (int) $stats['public'] ?></strong>
    </a>
    <a class="sf-stat" href="<?= $e($this->url->to('media/admin/videos')) ?>">
      <span>Views</span><strong><?= number_format((int) $stats['views']) ?></strong>
    </a>
  </div>

  <section class="sf-card">
    <div class="sf-card-h">
      <h2>Latest media</h2>
      <a href="<?= $e($this->url->to('media/admin/videos')) ?>">View all</a>
    </div>
    <?php if ($latest === []): ?>
      <p class="sf-empty">Nothing uploaded yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="sf-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Channel</th>
              <th>Type</th>
              <th>Status</th>
              <th>Views</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($latest as $row): ?>
              <tr>
                <td>
                  <a class="sf-title" href="<?= $e($this->url->to('media/watch', ['id' => (string) $row['video_id']])) ?>" target="_blank" rel="noopener">
                    <?= $e((string) $row['title']) ?>
                  </a>
                </td>
                <td><?= $e((string) $row['channel_title']) ?></td>
                <td><?= $e((string) $row['media_type']) ?></td>
                <td><?= $e(ucfirst((string) $row['status'])) ?></td>
                <td><?= number_format((int) $row['views_count']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
