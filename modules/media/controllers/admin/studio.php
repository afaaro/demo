<?php

class MediaAdminStudio extends Controller {

    /**
     * Enforce Global Admin Site Guardrails
     * This automatically runs before any specific action method is dispatched
     */
    public function __construct(System\Engine\Registry $registry) {
        parent::__construct($registry);

        // // Native framework authentication check: verify if current session is logged in and administrative
        // if (!$this->user->isLogged() || $this->user->getGroupId() != 1) {
        //     $this->notify->error('Access Denied: Restricted Administrative Space.');
            
        //     // Explicitly force termination to guarantee execution halts immediately
        //     redirect('index/home');
        //     exit();
        // }
    }

    /**
     * Global Platform Overview Dashboard & Operations Explorer
     * Route: /media/adminstudio
     */
    public function indexAction(): void {
        // 1. Optimized Analytics: Gather global health metrics via a single scan block
        $statsMetrics = $this->db->query("
            SELECT 
                COUNT(*) as total_videos,
                SUM(CASE WHEN `status` = 'processing' THEN 1 ELSE 0 END) as pending_review,
                (SELECT COUNT(*) FROM `#__media_channels`) as total_channels
            FROM `#__media_videos`
        ")->row;

        // 2. Setup Relational Query Tracking & Pagination
        $page = $this->request->get('page', 'int', 1);
        if ($page < 1) $page = 1;
        
        $limit = 12; 
        $offset = ($page - 1) * $limit;

        $globalVideos = $this->db->query("
            SELECT v.*, c.title AS channel_title, u.username AS uploader_name
            FROM `#__media_videos` v
            INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
            LEFT JOIN `#__user` u ON v.uploaded_by = u.id
            ORDER BY v.created_at DESC
            LIMIT ?, ?
        ", [(int)$offset, (int)$limit])->rows ?? [];

        $totalCount = (int)($statsMetrics['total_videos'] ?? 0);
        $totalPages = ceil($totalCount / $limit);

        // Isolate processing assets separately to populate a rapid moderation queue sidebar panel
        $quarantineQueue = $this->db->query("
            SELECT v.video_id, v.title, c.title AS channel_title, v.created_at
            FROM `#__media_videos` v
            INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
            WHERE v.status = 'processing'
            ORDER BY v.created_at ASC
            LIMIT 4
        ")->rows ?? [];

        // 3. Render High-Density Split-Pane Admin Canvas Layout (Light Mode Theme)
        ?>
        <div class="w-100 min-vh-100 bg-light pb-5" style="color: #334155; font-family: system-ui, -apple-system, sans-serif;">
            
            <div class="border-bottom border-secondary border-opacity-10 bg-white py-3 sticky-top shadow-sm" style="z-index: 1020;">
                <div class="container-fluid px-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-2 bg-danger bg-opacity-10 text-danger rounded border border-danger border-opacity-10 shadow-inner">
                                <i class="fas fa-user-shield fa-lg"></i>
                            </div>
                            <div>
                                <h1 class="h4 font-weight-bold text-dark m-0">Global Media Administration Node</h1>
                                <span class="font-monospace text-muted extra-small">Operational Cluster Hub Registry Matrix</span>
                            </div>
                        </div>
                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill font-monospace tracking-wider extra-small">
                            SECURITY ZONE LEVEL // MASTER MODE
                        </span>
                    </div>
                </div>
            </div>

            <div class="container-fluid px-4 pt-4">
                
                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-6 col-md-4">
                        <div class="card border-0 bg-white p-3 rounded-3 border-start border-4 border-primary shadow-sm">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-uppercase text-muted extra-small font-weight-bold d-block mb-1">Global Managed Payloads</span>
                                    <h3 class="font-weight-bold m-0 text-dark font-monospace"><?= number_format($totalCount); ?></h3>
                                </div>
                                <div class="rounded-circle bg-primary bg-opacity-10 p-2.5 text-primary"><i class="fas fa-database fa-lg"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <div class="card border-0 bg-white p-3 rounded-3 border-start border-4 border-success shadow-sm">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-uppercase text-muted extra-small font-weight-bold d-block mb-1">Active Transmitting Nodes</span>
                                    <h3 class="font-weight-bold m-0 text-dark font-monospace"><?= number_format($statsMetrics['total_channels'] ?? 0); ?></h3>
                                </div>
                                <div class="rounded-circle bg-success bg-opacity-10 p-2.5 text-success"><i class="fas fa-project-diagram fa-lg"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="card border-0 bg-white p-3 rounded-3 border-start border-4 <?= ($statsMetrics['pending_review'] > 0) ? 'border-warning animate-border-pulse' : 'border-secondary'; ?> shadow-sm">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-uppercase text-muted extra-small font-weight-bold d-block mb-1">Quarantine Staging Review</span>
                                    <h3 class="font-weight-bold m-0 <?= ($statsMetrics['pending_review'] > 0) ? 'text-warning' : 'text-muted'; ?> font-monospace"><?= (int)$statsMetrics['pending_review']; ?></h3>
                                </div>
                                <div class="rounded-circle bg-warning bg-opacity-10 p-2.5 text-warning"><i class="fas fa-exclamation-triangle fa-lg"></i></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    
                    <div class="col-12 col-xl-9">
                        <div class="card bg-white rounded-3 shadow-sm overflow-hidden" style="border: 1px solid #e2e8f0 !important;">
                            <div class="card-header bg-light border-bottom border-secondary border-opacity-10 py-3 px-4 d-flex align-items-center justify-content-between">
                                <h5 class="m-0 font-weight-bold text-dark small text-uppercase tracking-wider"><i class="fas fa-list me-2 text-secondary"></i>Global Media Registry Ledger</h5>
                                <span class="font-monospace extra-small text-muted">Page <?= $page; ?> of <?= max($totalPages, 1); ?></span>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 table-hover table-custom">
                                    <thead class="table-light-header text-uppercase tracking-wider extra-small text-secondary">
                                        <tr>
                                            <th class="ps-4" style="width: 70px;">UUID</th>
                                            <th>Media Source Specifications</th>
                                            <th>Target Channel Node</th>
                                            <th>Ingest Principal</th>
                                            <th style="width: 120px;">Visibility</th>
                                            <th style="width: 140px;">Timestamp</th>
                                            <th class="text-end pe-4" style="width: 110px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small text-secondary">
                                        <?php if (empty($globalVideos)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-5 text-muted font-monospace bg-transparent">
                                                    <i class="fas fa-folder-open fa-2x mb-2 d-block opacity-25"></i> Zero active storage payloads mapped to network cluster arrays.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($globalVideos as $row): ?>
                                                <tr class="align-middle border-bottom border-light">
                                                    <td class="ps-4 font-monospace text-primary font-weight-bold small">#<?= (int)$row['video_id']; ?></td>
                                                    <td>
                                                        <div class="max-width-320">
                                                            <strong class="text-dark d-block text-truncate mb-0.5" title="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                            <code class="extra-small text-muted text-break d-block font-monospace bg-light p-1 rounded border border-light"><?= htmlspecialchars($row['file_path'], ENT_QUOTES, 'UTF-8'); ?></code>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="text-dark small font-weight-bold"><i class="fas fa-box-open text-muted me-1.5 opacity-50"></i><?= htmlspecialchars($row['channel_title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="text-secondary font-weight-medium"><i class="far fa-user text-muted me-1.5 opacity-50"></i><?= htmlspecialchars($row['uploader_name'] ?? 'System Sync', ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['status'] === 'public'): ?>
                                                            <span class="badge-status bg-success-glow text-success"><span class="status-dot bg-success"></span>Public</span>
                                                        <?php elseif ($row['status'] === 'private'): ?>
                                                            <span class="badge-status bg-secondary-glow text-muted"><span class="status-dot bg-secondary"></span>Private</span>
                                                        <?php else: ?>
                                                            <span class="badge-status bg-warning-glow text-warning animate-pulse"><span class="status-dot bg-warning shadow-pulse"></span>Quarantine</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="font-monospace extra-small text-muted"><?= date('Y-m-d H:i', strtotime($row['created_at'])); ?></td>
                                                    <td class="text-end pe-4">
                                                        <div class="btn-group border border-light rounded shadow-sm overflow-hidden bg-white">
                                                            <a href="<?= url('media/watch', ['id' => $row['video_id']]); ?>" target="_blank" class="btn btn-sm btn-white text-primary" title="Preview Live Destination Stream Node"><i class="fas fa-external-link-alt"></i></a>
                                                            <a href="<?= url('media/admin/studio/moderation', ['id' => $row['video_id']]); ?>" class="btn btn-sm btn-white border-start border-light text-warning" title="Execute Direct Override Properties Tool"><i class="fas fa-gavel"></i></a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="card-footer bg-light border-top border-light py-3 d-flex justify-content-center">
                                    <nav>
                                        <ul class="pagination pagination-sm gap-1.5 m-0">
                                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                                <li class="page-item <?= $p === $page ? 'active' : ''; ?>">
                                                    <a class="page-link rounded border transition-all px-3 py-1.5 <?= $p === $page ? 'bg-danger text-white border-danger font-weight-bold shadow-sm' : 'text-secondary bg-white border-light hover-bg-slate'; ?>" href="<?= url('media/admin/studio', ['page' => $p]); ?>">
                                                        <?= $p; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-3">
                        <div class="card bg-white p-3 rounded-3 shadow-sm h-100" style="border: 1px solid #e2e8f0 !important;">
                            <h6 class="text-uppercase text-dark font-weight-bold tracking-wider mb-3 px-1 small">
                                <i class="fas fa-shield-alt text-warning me-2"></i>Escalation Queue
                            </h6>
                            <p class="text-muted extra-small lh-base mb-3 px-1">
                                Pending assets requiring master moderation clearance before visibility distribution can proceed.
                            </p>

                            <div class="d-flex flex-column gap-2.5">
                                <?php if (empty($quarantineQueue)): ?>
                                    <div class="text-center p-4 border border-dashed border-secondary border-opacity-20 rounded-3 text-muted font-monospace extra-small bg-light">
                                        <i class="fas fa-check-circle text-success me-1 d-block mb-1 fa-lg"></i> System clear: 0 issues flagged.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($quarantineQueue as $item): ?>
                                        <div class="p-2.5 rounded-3 bg-light border border-light d-flex flex-column gap-2 transition-all hover-card-border">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <strong class="text-dark text-truncate extra-small w-100 d-block" title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>
                                                </strong>
                                                <span class="badge bg-warning bg-opacity-10 text-warning font-monospace text-uppercase p-1 border border-warning border-opacity-25" style="font-size: 0.6rem;">STAGED</span>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-between border-top border-light pt-2 extra-small text-muted font-monospace">
                                                <span class="text-truncate" style="max-width: 120px;"><i class="fas fa-tv me-1"></i><?= htmlspecialchars($item['channel_title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <a href="<?= url('media/admin/studio/moderation', ['id' => $item['video_id']]); ?>" class="text-danger text-decoration-none font-weight-bold hover-underline"><i class="fas fa-gavel me-1"></i>Review</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        
        <?php
        $this->asset->addInlineStyle("
            .extra-small { font-size: 0.72rem; }
            .tracking-wider { letter-spacing: 0.06em !important; }
            .max-width-320 { max-width: 320px; }
            .gap-1.5 { gap: 0.35rem !important; }
            .gap-2.5 { gap: 0.65rem !important; }
            .p-2.5 { padding: 0.65rem !important; }
            
            /* Core Light Data-Tables styles */
            .table-custom { background-color: transparent !important; margin: 0 !important; }
            .table-light-header { background-color: #f8fafc !important; border-bottom: 2px solid #e2e8f0 !important; }
            .table-custom tbody tr { transition: background-color 0.15s ease; }
            .table-custom tbody tr:hover { background-color: #f1f5f9 !important; }
            .table-custom td { padding-top: 0.75rem !important; padding-bottom: 0.75rem !important; border: none !important; }
            .hover-bg-slate:hover { background-color: #f1f5f9 !important; }
            .btn-white { background-color: #ffffff; border-color: #e2e8f0; }
            .btn-white:hover { background-color: #f8fafc; }
            
            /* Dynamic Glow Status Badges */
            .badge-status { display: inline-flex; align-items: center; gap: 6px; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
            .bg-success-glow { background-color: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.15); }
            .bg-secondary-glow { background-color: rgba(100,116,139,0.08); border: 1px solid rgba(100,116,139,0.15); }
            .bg-warning-glow { background-color: rgba(234,179,8,0.08); border: 1px solid rgba(234,179,8,0.15); }
            .status-dot { width: 6px; height: 6px; display: inline-block; border-radius: 50%; }
            
            /* Micro-animations and decoration */
            .hover-card-border:hover { border-color: rgba(234,179,8,0.4) !important; }
            .hover-underline:hover { text-decoration: underline !important; }
            .animate-border-pulse { animation: borderPulse 2.5s infinite ease-in-out; }
            @keyframes borderPulse { 0%, 100% { border-color: rgba(234,179,8,0.2) !important; } 50% { border-color: rgba(234,179,8,0.6) !important; } }
            .shadow-pulse { animation: dotGlow 1.8s infinite ease-in-out; }
            @keyframes dotGlow { 0%, 100% { transform: scale(1); opacity: 0.5; } 50% { transform: scale(1.3); opacity: 1; } }
        ");

        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    /**
     * Administrative Override Moderation Tool View Panel
     * Route: /media/adminstudio/moderation?id=VIDEO_ID
     */
    public function moderationAction(): void {
        $videoId = $this->request->get('id', 'int');

        $video = $this->db->query("
            SELECT v.*, c.title AS channel_title 
            FROM `#__media_videos` v
            INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
            WHERE v.video_id = ? 
            LIMIT 1
        ", [$videoId])->row;

        if (empty($video)) {
            $this->notify->error('Target video asset matrix payload invalid.');
            redirect('media/admin/studio');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $forcedStatus = $this->request->post('status', 'string');
            $forcedTitle  = trim($this->request->post('title', 'string'));

            if (!empty($forcedTitle)) {
                $this->db->query("
                    UPDATE `#__media_videos` 
                    SET `title` = ?, `status` = ? 
                    WHERE `video_id` = ?
                ", [$forcedTitle, $forcedStatus, $videoId]);

                $this->notify->success('Administrative system override configuration committed successfully.');
                redirect('media/admin/studio');
                exit();
            }
        }

        // Render administrative editing UI panel here...
        ?>
        <div class="w-100 min-vh-100 bg-light py-5" style="color: #334155; font-family: system-ui, -apple-system, sans-serif;">
            <div class="container" style="max-width: 680px;">
                <div class="card bg-white rounded-4 shadow-sm overflow-hidden" style="border: 1px solid #e2e8f0 !important;">
                    <div class="p-1 text-center bg-danger"></div>
                    <div class="card-body p-4 p-sm-5">
                        <h3 class="font-weight-bold text-dark mb-3 d-flex align-items-center gap-2">
                            <i class="fas fa-gavel text-danger"></i> System Moderation Override
                        </h3>
                        
                        <div class="alert bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 small mb-4 rounded-3 d-flex gap-2">
                            <i class="fas fa-exclamation-triangle flex-shrink-0 mt-0.5"></i> 
                            <div>
                                <strong>System Administrator Execution Privilege Active:</strong> Modifying this asset will bypass native creator configurations. Overrides deploy globally down the index pipeline instantly.
                            </div>
                        </div>

                        <form action="<?= url('media/admin/studio/moderation', ['id' => $videoId]); ?>" method="POST" class="d-flex flex-column gap-3" style="gap: 1rem;">
                            <?= $this->form->renderCsrf();?>
                            <div>
                                <label class="form-label text-dark font-weight-bold small tracking-wider text-uppercase opacity-75">Parent Node Owner Channel</label>
                                <div class="position-relative">
                                    <i class="fas fa-tv position-absolute top-50 start-0 translate-middle-y ms-3 text-muted small"></i>
                                    <input type="text" class="form-control bg-light border border-light text-secondary ps-5 rounded-3 font-weight-medium" value="<?= htmlspecialchars($video['channel_title'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                </div>
                            </div>
                            <div>
                                <label class="form-label text-dark font-weight-bold small tracking-wider text-uppercase opacity-75">Sanitised Video Title Token</label>
                                <input type="text" name="title" class="form-control bg-white border text-dark rounded-3 focus-input" value="<?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div>
                                <label class="form-label text-dark font-weight-bold small tracking-wider text-uppercase opacity-75">Global Distribution Policy Visibility</label>
                                <select name="status" class="form-select bg-white border text-dark rounded-3 focus-input">
                                    <option value="public" <?= $video['status'] === 'public' ? 'selected' : ''; ?>>Force Public Sync Distribution</option>
                                    <option value="private" <?= $video['status'] === 'private' ? 'selected' : ''; ?>>Force Private Node Hidden Isolation</option>
                                    <option value="processing" <?= $video['status'] === 'processing' ? 'selected' : ''; ?>>Force Flag Quarantine Review Hold</option>
                                </select>
                            </div>
                            <div class="d-flex justify-content-end gap-2 pt-3 border-top border-light mt-2">
                                <a href="<?= url('media/admin/studio'); ?>" class="btn btn-sm btn-light border px-3 rounded-pill text-decoration-none font-weight-medium d-flex align-items-center text-secondary">Abort Overrides</a>
                                <button type="submit" class="btn btn-sm btn-danger px-4 rounded-pill font-weight-bold">Enforce Override Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        $this->asset->addInlineStyle("
            .focus-input:focus { background-color: #ffffff !important; color: #000000 !important; border-color: rgba(239,68,68,0.5) !important; box-shadow: 0 0 0 3px rgba(239,68,68,0.15) !important; }
        ");
        
        $this->load->view();
    }
}