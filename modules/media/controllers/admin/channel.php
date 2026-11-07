<?php

class MediaAdminChannel extends Controller {
    public function indexAction(): void {
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // Fetch total count for pagination math
        $total = $this->db->query("SELECT COUNT(*) as total FROM #__media_channels")->row['total'];

        // Core Aggregate Query: Pulls channel data, maps owner usernames, and calculates real-time video/staff counts
        $rows = $this->db->query("
            SELECT 
                c.*, 
                u.username AS owner_name,
                (SELECT COUNT(*) FROM #__media_videos v WHERE v.channel_id = c.channel_id) AS total_videos,
                (SELECT COUNT(*) FROM #__media_channel_staff s WHERE s.channel_id = c.channel_id) AS total_staff
            FROM #__media_channels c
            LEFT JOIN #__user u ON c.owner_id = u.id
            ORDER BY c.created_at DESC 
            LIMIT ?, ?
        ", [
            (int)$offset, 
            (int)$limit
        ])->rows ?? [];

        // Configure Grid Columns
        $this->table->setColumns([
            'channel_id'   => 'ID',
            'title'        => 'Channel Title',
            'owner_name'   => 'Owner Account',
            'total_videos' => 'Videos',
            'total_staff'  => 'Staff Members',
            'created_at'   => 'Date Formed',
            'action'       => function($row) {
                $viewUrl   = url('media/admin/channel/view', ['id' => $row['channel_id']]);
                $deleteUrl = url('media/admin/channel/delete', ['id' => $row['channel_id']]);
                
                return '
                    <div class="btn-group btn-group-sm">
                        <a href="' . $viewUrl . '" class="btn btn-light border" title="Manage Channel & Staff">
                            <i class="fas fa-users-cog text-primary"></i> Manage
                        </a>
                        <a href="' . $deleteUrl . '" class="btn btn-light border text-danger" onclick="return confirm(\'Warning! Deleting this channel will instantly purge all nested video records via cascading constraints. Proceed?\')" title="Delete Channel">
                            <i class="fas fa-trash"></i>
                        </a>
                    </div>
                ';
            }
        ]);

        $this->table->setRows($rows);
        $this->table->setPagination($total, $limit, $page);

        ?>
        <div class="container-fluid px-4 py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-tv text-secondary me-2"></i>Media Channels Management</h1>
                <a href="<?= url('media/admin/channel/add'); ?>" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Provision New Channel
                </a>
            </div>

            <div class="card shadow border-0">
                <div class="card-body p-0">
                    <?= $this->table->render(); ?>
                </div>
            </div>
        </div>
        <?php

        $this->load->view();
    }

    public function viewAction() {
        $channelId = (int)$this->request->get('id');

        // 1. Fetch channel core profile details and map its owner
        $channel = $this->db->query("
            SELECT c.*, u.username AS owner_name 
            FROM #__media_channels c 
            LEFT JOIN #__user u ON c.owner_id = u.id 
            WHERE c.channel_id = ? LIMIT 1
        ", [$channelId])->row;

        if (empty($channel)) {
            $this->notify->error('The requested media channel does not exist.');
            return redirect('media/admin/channel');
        }

        // 2. Fetch all nested videos uploaded under this channel container
        $videos = $this->db->query("
            SELECT v.*, u.username AS uploader_name 
            FROM #__media_videos v
            LEFT JOIN #__user u ON v.uploaded_by = u.id
            WHERE v.channel_id = ? 
            ORDER BY v.created_at DESC
        ", [$channelId])->rows ?? [];

        // 3. Fetch all assigned staff positions
        $staffMembers = $this->db->query("
            SELECT s.*, u.username, u.email 
            FROM #__media_channel_staff s
            LEFT JOIN #__user u ON s.user_id = u.id
            WHERE s.channel_id = ? 
            ORDER BY s.assigned_at ASC
        ", [$channelId])->rows ?? [];

        // 4. Handle Staff Assignment Form Processing
        $form = $this->form;
        $form->open(['action' => url('media/admin/channel/view', ['id' => $channelId])]);
        
        // Pull potential new staff candidates (exclude users who are already staff or the owner)
        $existingStaffIds = array_column($staffMembers, 'user_id');
        $usersResult = $this->db->query("SELECT id, username, email FROM #__user ORDER BY username ASC")->rows ?? [];
        
        $candidateOptions = [];
        foreach ($usersResult as $user) {
            if (!in_array($user['id'], $existingStaffIds)) {
                $candidateOptions[$user['id']] = htmlspecialchars($user['username'] . ' (' . $user['email'] . ')', ENT_QUOTES, 'UTF-8');
            }
        }

        $form->field('user_id', [
            'type'    => 'select',
            'label'   => 'Select Platform User',
            'options' => $candidateOptions,
            'value'   => ''
        ]);

        $form->field('role', [
            'type'    => 'select',
            'label'   => 'Assigned Permissions Level',
            'options' => ['manager' => 'Manager', 'editor' => 'Editor'],
            'value'   => 'editor'
        ]);

        $form->field('submit_btn', [
            'type'  => 'submit',
            'value' => 'Grant Access Permission',
            'attr'  => ['class' => 'btn btn-success w-100']
        ]);

        // Process staff assignment form execution
        if ($form->isValid()) {
            $cleanedData = $form->getData();
            
            $this->db->query("
                INSERT INTO `#__media_channel_staff` (`channel_id`, `user_id`, `role`) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `role` = VALUES(`role`)
            ", [$channelId, (int)$cleanedData['user_id'], $cleanedData['role']]);

            $this->notify->success('Permissions privileges updated successfully.');
            return redirect('media/admin/channel/view', ['id' => $channelId]);
        }

        ?>
        <div class="container-fluid px-4 py-4">
            <div class="mb-4">
                <a href="<?= url('media/admin/channel'); ?>" class="text-decoration-none small d-inline-flex align-items-center">
                    <i class="fas fa-chevron-left me-1"></i> Back to Channels Registry
                </a>
            </div>

            <div class="card shadow border-0 bg-light mb-4">
                <div class="card-body p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 font-weight-bold mb-1 text-dark"><?= htmlspecialchars($channel['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                        <p class="text-muted mb-0">
                            <span class="badge bg-primary me-2">Owner: <?= htmlspecialchars($channel['owner_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="small"><i class="fas fa-link me-1"></i> Slug: <strong><?= htmlspecialchars($channel['slug'], ENT_QUOTES, 'UTF-8'); ?></strong></span>
                        </p>
                    </div>
                    <div class="text-end">
                        <span class="text-muted small d-block">Created on</span>
                        <strong class="text-dark"><?= date('M d, Y', strtotime($channel['created_at'])); ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card shadow border-0 h-100">
                        <div class="card-header bg-white py-3">
                            <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-video me-2 text-secondary"></i>Uploaded Content Grid (<?= count($videos); ?>)</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($videos)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-video-slash fa-2x mb-3 d-block text-light"></i>
                                    No media files have been uploaded to this channel yet.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Title</th>
                                                <th>Uploader</th>
                                                <th>Status</th>
                                                <th>Date Uploaded</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($videos as $video): ?>
                                                <tr>
                                                    <td>
                                                        <span class="font-weight-bold text-dark d-block"><?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="text-muted small text-break"><?= htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($video['uploader_name'] ?? 'System / Deleted', ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td>
                                                        <span class="badge rounded-pill <?= $video['status'] === 'public' ? 'bg-success' : ($video['status'] === 'processing' ? 'bg-warning text-dark' : 'bg-secondary'); ?>">
                                                            <?= ucfirst($video['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="small text-muted"><?= date('M d, Y H:i', strtotime($video['created_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card shadow border-0 mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-user-plus me-2 text-success"></i>Delegate Staff Access</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($candidateOptions)): ?>
                                <div class="alert alert-info small mb-0">All registered system users are already mapped as staff on this channel.</div>
                            <?php else: ?>
                                <?= $form->render(); ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card shadow border-0">
                        <div class="card-header bg-white py-3">
                            <h5 class="m-0 font-weight-bold text-dark"><i class="fas fa-users me-2 text-primary"></i>Active Staff Roll Call</h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($staffMembers as $staff): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                        <div>
                                            <strong class="text-dark d-block"><?= htmlspecialchars($staff['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="text-muted small"><?= htmlspecialchars($staff['email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge <?= $staff['role'] === 'owner' ? 'bg-dark' : ($staff['role'] === 'manager' ? 'bg-info text-dark' : 'bg-light text-dark border'); ?> text-uppercase mb-1 d-inline-block">
                                                <?= $staff['role']; ?>
                                            </span>
                                            <?php if ($staff['role'] !== 'owner'): ?>
                                                <a href="<?= url('media/admin/channel/removestaff', ['channel_id' => $channelId, 'user_id' => $staff['user_id']]); ?>" 
                                                   class="text-danger d-block small text-decoration-none mt-1" 
                                                   onclick="return confirm('Revoke all channel privileges for this staff member?')">
                                                    Revoke Access
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
        $this->load->view();
    }

    public function addAction() {
        $form = $this->form;

        // 1. Pull system platform users to populate our owner selection dropdown mapping array
        $usersResult = $this->db->query("SELECT id, username, email FROM #__user ORDER BY username ASC")->rows ?? [];
        $ownerOptions = [];
        foreach ($usersResult as $user) {
            $ownerOptions[$user['id']] = htmlspecialchars($user['username'] . ' (' . $user['email'] . ')', ENT_QUOTES, 'UTF-8');
        }

        // 2. Map administrative form elements via your unchained builder matrix
        $form->open(['action' => url("media/admin/channel/add")]);
        
        $form->field('title', [
             'type'  => 'text',
             'label' => 'Channel Name / Title',
             'value' => ''
        ]);
        
        $form->field('slug', [
             'type'  => 'text',
             'label' => 'Custom SEO URL Slug (Leave blank to auto-generate)',
             'value' => ''
        ]);
        
        $form->field('owner_id', [
             'type'    => 'select',
             'label'   => 'Assign Primary Channel Owner Account',
             'options' => $ownerOptions,
             'value'   => ''
        ]);

        $form->field('submit_btn', [
            'type'  => 'submit',
            'value' => 'Provision Channel Instance',
            'attr'  => ['class' => 'btn btn-primary w-100']
        ]);

        // 3. Process execution validation tracks
        if ($form->isValid()) {
            $cleanedData = $form->getData();

            // Run fallback logic for empty slug paths using your core slug utility function
            $slug = !empty($cleanedData['slug']) ? slug($cleanedData['slug']) : slug($cleanedData['title']);
            $ownerId = (int)$cleanedData['owner_id'];

            // Guardrail check: Verify that this clean routing slug doesn't already exist on the platform
            $duplicateCheck = $this->db->query("SELECT channel_id FROM #__media_channels WHERE slug = ? LIMIT 1", [$slug])->row;
            if (!empty($duplicateCheck)) {
                $this->notify->error('The target SEO URL slug matches an active entity already running on the platform.');
                return redirect('media/admin/channel/add');
            }

            // 4. Atomic Transaction Sweep: Provision the channel and seed the staff mapping array simultaneously
            $this->db->transaction(function() use ($cleanedData, $slug, $ownerId) {
                // Step A: Insert the core channel container
                $this->db->query(
                    "INSERT INTO `#__media_channels` (`owner_id`, `title`, `slug`) VALUES (?, ?, ?)",
                    [$ownerId, trim($cleanedData['title']), $slug]
                );
                
                // Fetch the fresh database-assigned auto-increment primary key ID
                $newChannelId = (int)$this->db->insert_id();

                // Step B: Inject the owner directly into the staff pivot table as 'owner'
                // This ensures your model's checkChannelPermission() functions validate perfectly for them
                $this->db->query(
                    "INSERT INTO `#__media_channel_staff` (`channel_id`, `user_id`, `role`) VALUES (?, ?, 'owner')",
                    [$newChannelId, $ownerId]
                );
            });

            $this->notify->success('The brand-new channel container was successfully provisioned and linked to its owner account.');
            return redirect('media/admin/channel');
        }

        ?>
        <div class="container-fluid px-4 py-4">
            <div class="mb-4">
                <a href="<?= url('media/admin/channel'); ?>" class="text-decoration-none small d-inline-flex align-items-center">
                    <i class="fas fa-chevron-left me-1"></i> Back to Channels Registry
                </a>
            </div>

            <div class="card shadow border-0 max-width-700 mx-auto">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="m-0 font-weight-bold text-primary"><i class="fas fa-plus-circle me-2"></i>Provision New Channel Container</h5>
                </div>
                <div class="card-body p-4">
                    <?= $form->render(); ?>
                </div>
            </div>
        </div>
        <?php
        $this->load->view();
    }

    public function deleteAction() {
        if (!$this->confirmPost("Delete this media channel and its related records?")) {
            return;
        }

        $id = (int)$this->request->get('id');

        // Execute structural deletion directly
        // The foreign key constraint ON DELETE CASCADE handles cleaning up #__media_videos and links automatically!
        $this->db->query("DELETE FROM `#__media_channels` WHERE `channel_id` = ?", [$id]);
        $this->db->query("DELETE FROM `#__media_channel_staff` WHERE `channel_id` = ?", [$id]);

        $this->notify->success('The selected channel and its cascading database relational dependencies were completely uninstalled.');
        return redirect('media/admin/channel');
    }

    /**
     * Revoke Privileges Endpoint for Channel Staff Management
     */
    public function removestaffAction() {
        if (!$this->confirmPost("Remove this staff member from the channel?")) {
            return;
        }

        $channelId = (int)$this->request->get('channel_id');
        $userId = (int)$this->request->get('user_id');

        // Prevent cross-mutating primary owner nodes safety guardrail
        $check = $this->db->query("SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1", [$channelId, $userId])->row;
        
        if (!empty($check) && $check['role'] === 'owner') {
            $this->notify->error('The Primary Channel Owner account privileges cannot be revoked via staff delegation views.');
        } else {
            $this->db->query("DELETE FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ?", [$channelId, $userId]);
            $this->notify->success('Staff privileges revoked successfully.');
        }

        return redirect('media/admin/channel/view', ['id' => $channelId]);
    }
}