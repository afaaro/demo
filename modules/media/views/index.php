<div class="row g-4 p-3">
    <?php if (empty($feedItems)): ?>
        <div class="col-12 text-center py-5">
            <p class="text-muted fs-5">No media found matching your selection.</p>
        </div>
    <?php else: ?>
        <?php foreach ($feedItems as $item): ?>
            <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                <div class="position-relative yt-video-card-anchor">
                    <a href="<?= url('media/watch', ['id' => $item['video_id']]); ?>" class="card-hitbox-overlay"></a>

                    <div class="position-relative ratio ratio-16x9 overflow-hidden rounded-3 bg-dark mb-2 thumbnail-container">
                        <img src="<?= htmlspecialchars($item['thumbnail_path'] ?: THEME_URL . 'assets/images/default-thumb.jpg'); ?>" 
                             class="w-100 h-100 object-cover scaling-target" 
                             alt="Thumbnail">
                        <div class="position-absolute bottom-0 end-0 m-2">
                            <span class="yt-time-badge"><?= htmlspecialchars($item['duration'] ?? '0:00'); ?></span>
                        </div>
                    </div>

                    <div class="d-flex px-1">
                        <a href="<?= url('media/channel', ['id' => $item['channel_id']]); ?>" class="position-relative z-index-5 me-2 mt-1">
                            <img src="<?= htmlspecialchars($item['channel_avatar'] ?: THEME_URL . 'assets/images/default-avatar.jpg'); ?>" 
                                 class="rounded-circle object-cover" 
                                 style="width: 36px; height: 36px;" 
                                 alt="Avatar">
                        </a>
                        <div class="flex-grow-1">
                            <h4 class="yt-title-text-clamped mb-1" title="<?= htmlspecialchars($item['title']); ?>">
                                <?= htmlspecialchars($item['title']); ?>
                            </h4>
                            <div class="d-flex flex-column">
                                <a href="<?= url('media/channel', ['id' => $item['channel_id']]); ?>" class="yt-channel-link text-decoration-none d-inline-flex align-items-center">
                                    <?= htmlspecialchars($item['channel_title']); ?>
                                    <i class="bi bi-check-circle-fill verified-check-icon ms-1" title="Verified"></i>
                                </a>
                                <span class="yt-card-metadata">
                                    <?= number_format($item['views'] ?? 0); ?> views &bull; <?= date('M d, Y', strtotime($item['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>