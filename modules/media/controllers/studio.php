<?php

class MediaStudio extends Controller {

    /**
     * Creator Studio Main Dashboard Hub
     * Route: /media/studio
     */
    public function indexAction() {
        $currentUserId = $this->auth->user('id');

        // 1. Fetch all channels this specific user has staff or owner access rights to manage
        $accessibleChannels = $this->db->query("
            SELECT c.*, s.role 
            FROM #__media_channels c
            INNER JOIN #__media_channel_staff s ON c.channel_id = s.channel_id
            WHERE s.user_id = ?
            ORDER BY c.title ASC
        ", [$currentUserId])->rows ?? [];

        if (empty($accessibleChannels)) {
            $this->renderNoChannelWorkspace();
            return;
        }

        // 2. Manage active channel context selection via session or request string parameters
        $activeChannelId = $this->request->get('channel_id', 'int');
        
        $activeChannel = null;
        foreach ($accessibleChannels as $channel) {
            if ($activeChannelId === (int)$channel['channel_id']) {
                $activeChannel = $channel;
                break;
            }
        }

        if (!$activeChannel) {
            $activeChannel = $accessibleChannels[0];
            $activeChannelId = (int)$activeChannel['channel_id'];
        }

        // 3. Compile Real-Time Dashboard Channel Analytics Performance Metrics
        $metrics = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM #__media_videos WHERE channel_id = ?) AS total_videos,
                (SELECT COUNT(*) FROM #__media_channel_staff WHERE channel_id = ?) AS total_staff
        ", [$activeChannelId, $activeChannelId])->row;

        // 4. Fetch the Media Content Ledger for this active workspace context
        $page = $this->request->get('page', 'int', 1);
        $limit = 8;
        $offset = ($page - 1) * $limit;

        $videos = $this->db->query("
            SELECT v.*, u.username AS uploader_name
            FROM #__media_videos v
            LEFT JOIN #__user u ON v.uploaded_by = u.id
            WHERE v.channel_id = ?
            ORDER BY v.created_at DESC
            LIMIT ?, ?
        ", [$activeChannelId, (int)$offset, (int)$limit])->rows ?? [];

        ?>
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center border-bottom pb-4 mb-4">
                <div>
                    <h1 class="h3 font-weight-bold mb-1 text-dark">
                        <i class="fas fa-photo-video text-primary me-2"></i>Creator Studio
                    </h1>
                    <p class="text-muted mb-0">Managing Workspace Matrix for <strong><?= htmlspecialchars($activeChannel['title'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                </div>

                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <?php if (count($accessibleChannels) > 1): ?>
                        <select class="form-select border-secondary-subtle" onchange="location = this.value;" style="min-width: 220px;">
                            <?php foreach ($accessibleChannels as $ch): ?>
                                <option value="<?= url('media/studio', ['channel_id' => $ch['channel_id']]); ?>" <?= (int)$ch['channel_id'] === $activeChannelId ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8'); ?> (<?= ucfirst($ch['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <a href="<?= url('media/upload', ['channel_id' => $activeChannelId]); ?>" class="btn btn-danger px-4">
                        <i class="fas fa-upload me-1"></i> Upload Media
                    </a>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card shadow border-0 p-3 bg-white">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Your Role Clearance</span>
                                <span class="badge bg-dark px-3 py-2 text-uppercase fs-7"><?= $activeChannel['role']; ?></span>
                            </div>
                            <div class="bg-light p-3 rounded-circle text-secondary"><i class="fas fa-user-shield fa-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card shadow border-0 p-3 bg-white">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Total Assets</span>
                                <h3 class="font-weight-bold mb-0 text-dark"><?= $metrics['total_videos']; ?></h3>
                            </div>
                            <div class="bg-light p-3 rounded-circle text-danger"><i class="fas fa-folder-open fa-lg"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card shadow border-0 p-3 bg-white">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted small text-uppercase font-weight-bold d-block mb-1">Active Team Members</span>
                                <h3 class="font-weight-bold mb-0 text-dark"><?= $metrics['total_staff']; ?></h3>
                            </div>
                            <div class="bg-light p-3 rounded-circle text-primary"><i class="fas fa-users fa-lg"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow border-0">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-list me-2 text-muted"></i>Channel Content Inventory Ledger</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($videos)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-film fa-3x mb-3 d-block text-secondary-subtle"></i>
                            This workspace channel container has not processed any content assets yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 px-3">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-4">Media Asset Metadata Profile</th>
                                        <th>Uploaded By</th>
                                        <th>Visibility State</th>
                                        <th>Ingest Date</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($videos as $video): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <?php if (!empty($video['thumbnail_path'])): ?>
                                                        <img src="<?= url($video['thumbnail_path']); ?>" class="rounded me-3 shadow-sm object-fit-cover" style="width: 60px; height: 38px;" alt="Video Frame">
                                                    <?php else: ?>
                                                        <div class="bg-dark text-white d-flex align-items-center justify-content-center rounded me-3 shadow-sm" style="width: 60px; height: 38px;">
                                                            <?php 
                                                                if (strpos($video['file_path'], '/audio/') !== false) {
                                                                    echo '<i class="fas fa-music text-info opacity-75"></i>';
                                                                } elseif (strpos($video['file_path'], '/images/') !== false) {
                                                                    echo '<i class="fas fa-image text-warning opacity-75"></i>';
                                                                } else {
                                                                    echo '<i class="fas fa-play small opacity-50"></i>';
                                                                }
                                                            ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div>
                                                        <strong class="text-dark d-block"><?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                        <span class="text-muted small text-break d-block" style="max-width: 450px;"><?= htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="text-dark"><?= htmlspecialchars($video['uploader_name'] ?? 'System Sync', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td>
                                                <span class="badge rounded-pill <?= $video['status'] === 'public' ? 'bg-success-subtle text-success border border-success' : ($video['status'] === 'processing' ? 'bg-warning-subtle text-warning border border-warning' : 'bg-secondary-subtle text-secondary border'); ?> px-3">
                                                    <?= ucfirst($video['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted small"><?= date('M d, Y', strtotime($video['created_at'])); ?></td>
                                            <td class="text-end pe-4">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= url('media/studio/edit', ['id' => $video['video_id'], 'channel_id' => $activeChannelId]); ?>" class="btn btn-light border btn-sm" title="Edit Metadata">
                                                        <i class="fas fa-edit text-muted"></i> Edit
                                                    </a>
                                                    <?php if (in_array($activeChannel['role'], ['owner', 'manager'])): ?>
                                                        <form method="post" action="<?= url('media/studio/deletevideo', ['id' => $video['video_id'], 'channel_id' => $activeChannelId]); ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this media asset and all associated server files permanently?')">
                                                            <?= $this->request->csrfField() ?>
                                                            <button type="submit" class="btn btn-light border text-danger btn-sm" title="Delete Asset"><i class="fas fa-trash"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php

        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    /**
     * Edit Video Metadata Properties
     * Route: /media/studio/edit
     */
    public function editAction() {
        $videoId   = $this->request->get('id', 'int');
        $channelId = $this->request->get('channel_id', 'int');
        $currentUserId = $this->auth->user('id');

        $allowed = $this->db->query(
            "SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1",
            [$channelId, $currentUserId]
        )->row;

        if (empty($allowed)) {
            $this->notify->error('Unauthorized workspace access modification block.');
            redirect('media/studio');
            return;
        }

        $video = $this->db->query(
            "SELECT * FROM `#__media_videos` WHERE `video_id` = ? AND `channel_id` = ? LIMIT 1",
            [$videoId, $channelId]
        )->row;

        if (empty($video)) {
            $this->notify->error('The requested video asset registry node could not be found.');
            redirect('media/studio', ['channel_id' => $channelId]);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $updatedTitle  = trim($this->request->post('title', 'string'));
            $updatedStatus = $this->request->post('status', 'string');

            if (!in_array($updatedStatus, ['public', 'private', 'processing'])) {
                $updatedStatus = 'private';
            }

            if (empty($updatedTitle)) {
                $this->notify->error('Video display title cannot be empty.');
            } else {
                $this->db->query("
                    UPDATE `#__media_videos` 
                    SET `title` = ?, `status` = ? 
                    WHERE `video_id` = ? AND `channel_id` = ?
                ", [$updatedTitle, $updatedStatus, $videoId, $channelId]);

                $this->notify->success('Media metadata synchronized successfully.');
                redirect('media/studio', ['channel_id' => $channelId]);
                return;
            }
        }

        ?>
        <div class="container py-5" style="max-width: 800px;">
            <div class="d-flex align-items-center mb-4">
                <a href="<?= url('media/studio', ['channel_id' => $channelId]); ?>" class="btn btn-light border btn-sm me-3">
                    <i class="fas fa-arrow-left"></i> Studio
                </a>
                <h1 class="h3 font-weight-bold m-0 text-dark"><i class="fas fa-edit text-muted me-2"></i>Edit Media Details</h1>
            </div>

            <div class="card shadow border-0">
                <div class="card-body p-5">
                    <form action="<?= url('media/studio/edit', ['id' => $videoId, 'channel_id' => $channelId]); ?>" method="POST">
                        
                        <div class="mb-4 bg-light p-3 rounded border border-secondary-subtle d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted extra-small text-uppercase font-weight-bold d-block mb-1">Target Storage Path Reference</span>
                                <code class="text-break text-dark font-weight-bold"><?= htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?></code>
                            </div>
                            <?php if (!empty($video['thumbnail_path'])): ?>
                                <img src="<?= url($video['thumbnail_path']); ?>" class="rounded border shadow-sm img-thumbnail" style="width: 80px; max-height: 50px; object-fit: cover;">
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label font-weight-bold text-dark">Asset Title</label>
                            <input type="text" name="title" class="form-control form-control-lg border-secondary-subtle" value="<?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off">
                        </div>

                        <div class="mb-4">
                            <label class="form-label font-weight-bold text-dark">Visibility State Pipeline</label>
                            <select name="status" class="form-select border-secondary-subtle">
                                <option value="public" <?= $video['status'] === 'public' ? 'selected' : ''; ?>>Public (Visible globally)</option>
                                <option value="private" <?= $video['status'] === 'private' ? 'selected' : ''; ?>>Private (Only visible to staff)</option>
                                <option value="processing" <?= $video['status'] === 'processing' ? 'selected' : ''; ?>>Staging / Processing Mode</option>
                            </select>
                        </div>

                        <hr class="my-4 text-secondary opacity-25">

                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?= url('media/studio', ['channel_id' => $channelId]); ?>" class="btn btn-light border px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 font-weight-bold">Commit Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
        $this->load->view();
    }

    /**
     * Delete Video Permanently and Unlink Filesystem Assets (Including Thumbnail files)
     * Route: /media/studio/deletevideo
     */
    public function deletevideoAction() {
        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            $this->notify->error('Invalid request. Please use the delete button and try again.');
            redirect('media/studio', ['channel_id' => $this->request->get('channel_id', 'int')]);
            return;
        }

        $videoId   = $this->request->get('id', 'int');
        $channelId = $this->request->get('channel_id', 'int');
        $currentUserId = $this->auth->user('id');

        $allowed = $this->db->query(
            "SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1",
            [$channelId, $currentUserId]
        )->row;

        if (empty($allowed) || !in_array($allowed['role'], ['owner', 'manager'])) {
            $this->notify->error('Elevated administrative privilege validation block. Deletion rejected.');
            redirect('media/studio', ['channel_id' => $channelId]);
            return;
        }

        // Fetch row data to capture BOTH file paths
        $video = $this->db->query(
            "SELECT `file_path`, `thumbnail_path` FROM #__media_videos WHERE `video_id` = ? AND `channel_id` = ? LIMIT 1",
            [$videoId, $channelId]
        )->row;

        if (!empty($video)) {
            $absoluteRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

            // 1. Physically drop primary media file asset (video/audio/image)
            $this->removeMediaFile($absoluteRoot, (string) $video['file_path']);

            // 2. Physically drop associated thumbnail file asset if it exists
            if (!empty($video['thumbnail_path'])) {
                $this->removeMediaFile($absoluteRoot, (string) $video['thumbnail_path']);
            }

            // 3. Clean record row out of your relational database engine container
            $this->db->query("DELETE FROM `#__media_videos` WHERE `video_id` = ? AND `channel_id` = ?", [$videoId, $channelId]);
            
            $this->notify->success('Media asset binaries and registry tracking blocks unlinked successfully.');
        } else {
            $this->notify->error('The video entity could not be found or has already been dropped.');
        }

        redirect('media/studio', ['channel_id' => $channelId]);
    }

    protected function renderNoChannelWorkspace(): void {
        ?>
        <div class="container py-5 text-center">
            <div class="max-width-600 mx-auto py-5">
                <i class="fas fa-user-slash fa-4x text-secondary-subtle mb-4"></i>
                <h2 class="font-weight-bold text-dark mb-2">No Active Studios Channels Mapped</h2>
                <p class="text-muted">Your account does not currently hold management role configurations or staff invitations for any media channel entities running on this platform.</p>
                <div class="mt-4">
                    <a href="<?= url('home'); ?>" class="btn btn-primary px-4">Return Home</a>
                </div>
            </div>
        </div>
        <?php
        $this->load->view();
    }

    /** Delete a stored media file, refusing anything that resolves outside uploads/media. */
    private function removeMediaFile(string $absoluteRoot, string $relativePath): void
    {
        $root = realpath($absoluteRoot . 'uploads' . DIRECTORY_SEPARATOR . 'media');
        $real = realpath($absoluteRoot . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
        if ($root !== false && $real !== false && is_file($real) && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            unlink($real);
        }
    }
}
