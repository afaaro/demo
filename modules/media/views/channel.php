<div class="bg-white min-vh-100 pb-5">
    
    <div class="w-100 bg-secondary position-relative shadow-inner overflow-hidden" style="height: 280px; background: url('<?= !empty($channel['banner_path']) ? url('') . $channel['banner_path'] : ''; ?>') center/cover no-repeat;">
        <?php if (empty($channel['banner_path'])): ?>
            <div class="w-100 h-100 opacity-25 bg-gradient-dark d-flex align-items-center justify-content-center">
                <i class="fas fa-photo-video fa-5x text-white"></i>
            </div>
        <?php endif; ?>
    </div>

    <div class="container-fluid px-md-5 mb-5 position-relative" style="margin-top: -50px; max-width: 1500px;">
        <div class="d-flex flex-column flex-sm-row align-items-center align-items-sm-end text-center text-sm-start gap-4">
            
            <div class="rounded-circle bg-white p-1 shadow border border-light position-relative z-index-1" style="width: 130px; height: 130px;">
                <div class="w-100 h-100 rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="background: url('<?= !empty($channel['avatar']) ? url('') . $channel['avatar'] : ''; ?>') center/cover no-repeat;">
                    <?php if (empty($channel['avatar'])): ?>
                        <i class="fas fa-tv fa-2x opacity-50"></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-sm-2 flex-grow-1">
                <h1 class="h2 font-weight-bold mb-1 text-dark d-flex align-items-center justify-content-center justify-content-sm-start gap-2">
                    <?= htmlspecialchars($channel['title'], ENT_QUOTES, 'UTF-8'); ?>
                    <i class="fas fa-check-circle text-primary fs-6" title="Verified Creator Hub"></i>
                </h1>
                <p class="text-muted mb-0 max-width-600 line-height-sm small">
                    <?= !empty($channel['description']) ? htmlspecialchars($channel['description'], ENT_QUOTES, 'UTF-8') : 'Welcome to the official content platform hub for ' . htmlspecialchars($channel['title'], ENT_QUOTES, 'UTF-8') . '.'; ?>
                </p>
            </div>

            <div class="mb-sm-2 flex-shrink-0 text-center text-sm-end">
                <span class="badge bg-light text-dark border px-3 py-2 font-weight-bold fs-7 shadow-sm">
                    <i class="fas fa-video me-1 text-danger"></i> <?= $totalVideosCount; ?> Public Content Assets
                </span>
            </div>
        </div>

        <hr class="mt-4 mb-4 text-secondary opacity-25">

        <div class="row g-4">
            <?php if (empty($videos)): ?>
                <div class="col-12 text-center py-5 text-muted">
                    <i class="fas fa-film fa-3x mb-3 d-block text-secondary-subtle"></i>
                    This creative profile channel canvas has not authorized any public media items.
                </div>
            <?php else: ?>
                <?php foreach ($videos as $video): ?>
                    <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-xxl-2">
                        <a href="<?= url('media/watch', ['id' => $video['video_id']]); ?>" class="card border-0 text-decoration-none h-100 bg-transparent group-hover-lift">
                            
                            <div class="ratio ratio-16x9 bg-dark text-white rounded shadow-sm overflow-hidden mb-2 border border-light position-relative">
                                <?php 
                                    $displayThumb = !empty($video['thumbnail_path']) ? $video['thumbnail_path'] : THEME_URL . 'assets/images/default-thumb.jpg';
                                    debug($displayThumb);
                                ?>
                                <img src="<?= htmlspecialchars($displayThumb); ?>" 
                                     class="w-100 h-100 object-cover scaling-target" 
                                     alt="Video Thumbnail">
                                
                                <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center opacity-0 gradient-overlay">
                                    <i class="fas fa-play text-white fa-lg transition-transform scale-hover"></i>
                                </div>
                            </div>

                            <div class="px-1">
                                <strong class="text-dark d-block font-weight-bold small text-truncate mb-0" title="<?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                                <span class="text-muted extra-small d-block"><?= date('M d, Y', strtotime($video['created_at'])); ?></span>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="d-flex justify-content-center mt-5">
                <ul class="pagination pagination-sm gap-1 border-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : ''; ?>">
                            <a class="page-link rounded px-3 border border-secondary-subtle <?= $p === $page ? 'bg-dark text-white border-dark shadow-sm' : 'text-secondary bg-white'; ?>" href="<?= url('media/channel', ['id' => $channelId, 'page' => $p]); ?>">
                                <?= $p; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>