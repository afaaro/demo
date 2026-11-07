<?php

class MediaWatch extends Controller {
    public function indexAction() {
        $videoId = $this->request->get('id', 'int');

        if (empty($videoId)) {
            $this->notify->error('Invalid playback parameters provided.');
            return redirect('index/home');
        }

        // 1. Fetch main media payload metadata along with parental channel details
        $video = $this->db->query("
            SELECT v.*, c.title AS channel_title, c.avatar AS channel_avatar
            FROM `#__media_videos` v
            INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
            WHERE v.video_id = ? AND v.status = 'public'
            LIMIT 1
        ", [$videoId])->row;

        if (empty($video)) {
            $this->notify->error('The requested media asset does not exist or has been privatized.');
            return redirect('home');
        }

        // 2. Fetch aggregate real-time interaction loop metrics
        $likesCount = $this->db->query("
            SELECT COUNT(*) AS total FROM `#__media_video_reactions` 
            WHERE `video_id` = ? AND `type` = 'like'
        ", [$videoId])->row['total'] ?? 0;
        
        $dislikesCount = $this->db->query("
            SELECT COUNT(*) AS total FROM `#__media_video_reactions` 
            WHERE `video_id` = ? AND `type` = 'dislike'
        ", [$videoId])->row['total'] ?? 0;

        $comments = $this->db->query("
            SELECT c.*, u.username 
            FROM `#__media_video_comments` c
            INNER JOIN `#__user` u ON c.user_id = u.id
            WHERE c.video_id = ?
            ORDER BY c.created_at DESC
        ", [$videoId])->rows ?? [];

        // 3. Fetch a sidebar list of recommendation clips (excluding the active asset)
        $recommendations = $this->db->query("
            SELECT v.video_id, v.title, v.file_path, v.thumbnail_path, v.created_at, c.title AS channel_title
            FROM `#__media_videos` v
            INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
            WHERE v.video_id != ? AND v.status = 'public'
            ORDER BY RAND()
            LIMIT 6
        ", [$videoId])->rows ?? [];

        // 4. Render the watching interface layout
        ?>
        <div class="container-fluid py-4 bg-light" style="max-width: 1600px; min-height: 90vh;">
            <div class="row g-4">
                
                <div class="col-12 col-xl-8">
                    
                    <div class="ratio ratio-16x9 bg-black rounded shadow overflow-hidden mb-3 position-relative">
                        <?php if (strpos($video['file_path'], '/audio/') !== false): ?>
                            <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center p-4 text-center select-none" style="<?= !empty($video['thumbnail_path']) ? "background: linear-gradient(rgba(15,23,42,0.85), rgba(15,23,42,0.95)), url('".url($video['thumbnail_path'])."') center/cover no-repeat;" : 'background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);'; ?>">
                                <div class="audio-animation-wrapper mb-4">
                                    <i class="fas fa-music text-info fa-4x mb-2 pulse-slow"></i>
                                </div>
                                <audio id="main-platform-player" controls autoplay controlsList="nodownload" class="custom-audio-player mx-auto w-75 shadow-lg">
                                    <source src="<?= url('') . htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?>" type="audio/mpeg">
                                    Your browser does not support the HTML5 audio engine.
                                </audio>
                            </div>
                        <?php elseif (strpos($video['file_path'], '/images/') !== false): ?>
                            <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-dark">
                                <img id="main-platform-player" src="<?= url('') . htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?>" class="img-fluid mh-100 object-fit-contain" alt="<?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        <?php else: ?>
                            <video id="main-platform-player" controls autoplay controlsList="nodownload" class="w-100 h-100" poster="<?= !empty($video['thumbnail_path']) ? url($video['thumbnail_path']) : ''; ?>">
                                <source src="<?= url('') . htmlspecialchars($video['file_path'], ENT_QUOTES, 'UTF-8'); ?>" type="video/mp4">
                                Your browser does not support the HTML5 video streaming engine.
                            </video>
                        <?php endif; ?>
                    </div>

                    <div class="card border-0 shadow-sm p-4 rounded mb-4">
                        <h1 class="h4 font-weight-bold mb-2 text-dark"><?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                        
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 bg-light p-3 rounded my-2 border border-light-subtle">
                            <div class="text-secondary small">
                                <i class="fas fa-eye text-primary me-1"></i> 
                                <strong id="live-views-label" class="text-dark font-weight-bold"><?= $video['views_count']; ?></strong> platform views 
                                <span class="mx-2 text-muted">•</span>
                                Published on <?= date('M d, Y', strtotime($video['created_at'])); ?>
                            </div>
                            <div class="btn-group shadow-sm bg-white rounded">
                                <button onclick="sendVideoReaction('like')" class="btn btn-sm btn-outline-primary px-3 border-0 py-2">
                                    <i class="fas fa-thumbs-up me-1"></i> <span id="likes-badge"><?= $likesCount; ?></span>
                                </button>
                                <div class="vr opacity-25 my-2"></div>
                                <button onclick="sendVideoReaction('dislike')" class="btn btn-sm btn-outline-secondary px-3 border-0 py-2">
                                    <i class="fas fa-thumbs-down me-1"></i> <span id="dislikes-badge"><?= $dislikesCount; ?></span>
                                </button>
                            </div>
                        </div>

                        <hr class="my-3 text-secondary opacity-25">

                        <div class="d-flex align-items-center mb-1">
                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center shadow-sm border border-2 border-white me-3" style="width: 52px; height: 52px; background: url('<?= !empty($video['channel_avatar']) ? url('') . $video['channel_avatar'] : ''; ?>') center/cover no-repeat;">
                                <?php if(empty($video['channel_avatar'])): ?>
                                    <i class="fas fa-tv text-white-50"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h5 class="m-0 font-weight-bold text-dark"><?= htmlspecialchars($video['channel_title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                                <span class="text-muted small">Verified Content Creator Space</span>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm p-4 rounded-3 mb-4">
                        <h5 class="mb-4 font-weight-bold text-dark">
                            <i class="far fa-comments me-2 text-primary"></i>Discussion Thread
                        </h5>
                        
                        <div class="mb-4 bg-light p-3 rounded border">
                            <label class="form-label font-weight-bold small text-secondary">Share Your Feedback</label>
                            <textarea id="user-comment-input" class="form-control mb-3 border-0 bg-white shadow-inner" rows="2" placeholder="Join the platform conversation..."></textarea>
                            <div class="d-flex justify-content-end">
                                <button onclick="submitUserCommentThread()" class="btn btn-primary btn-sm px-4 font-weight-bold rounded-pill shadow-sm">Post Comment</button>
                            </div>
                        </div>

                        <div id="dynamic-comments-wrapper" class="d-flex flex-column gap-3">
                            <?php if (empty($comments)): ?>
                                <div id="empty-comment-notice" class="text-center py-4 text-muted small border border-dashed rounded bg-white">
                                    Be the first to leave a comment on this media upload item!
                                </div>
                            <?php else: ?>
                                <?php foreach ($comments as $com): ?>
                                    <div class="border-bottom pb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <strong class="text-dark small"><i class="far fa-user-circle text-primary me-1"></i><?= htmlspecialchars($com['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="text-muted font-monospace extra-small"><?= date('M d, Y H:i', strtotime($com['created_at'])); ?></span>
                                        </div>
                                        <p class="m-0 text-secondary small ps-3"><?= nl2br(htmlspecialchars($com['comment_text'], ENT_QUOTES, 'UTF-8')); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4">
                    <h6 class="text-uppercase text-muted font-weight-bold mb-3 tracking-wider"><i class="fas fa-play-circle me-2"></i>Up Next</h6>
                    
                    <div class="d-flex flex-column gap-3">
                        <?php if (empty($recommendations)): ?>
                            <div class="text-muted small text-center py-4 bg-white rounded border border-dashed">
                                No recommendations matching this file type parameters.
                            </div>
                        <?php else: ?>
                            <?php foreach ($recommendations as $rec): ?>
                                <a href="<?= url('media/watch', ['id' => $rec['video_id']]); ?>" class="text-decoration-none card border-0 shadow-sm h-100 overflow-hidden hover-card-lift">
                                    <div class="card-body p-0">
                                        <div class="d-flex flex-row">
                                            <div class="bg-dark text-white d-flex align-items-center justify-content-center position-relative shadow-inner flex-shrink-0" style="width: 140px; height: 85px; <?= !empty($rec['thumbnail_path']) ? "background: url('".url($rec['thumbnail_path'])."') center/cover no-repeat;" : ''; ?>">
                                                <div class="w-100 h-100 d-flex align-items-center justify-content-center position-absolute top-0 start-0 visual-filter-overlay">
                                                    <?php 
                                                        if (strpos($rec['file_path'], '/audio/') !== false) {
                                                            echo '<i class="fas fa-music text-info opacity-75"></i>';
                                                        } elseif (strpos($rec['file_path'], '/images/') !== false) {
                                                            echo '<i class="fas fa-image text-warning opacity-75"></i>';
                                                        } else {
                                                            echo '<i class="fas fa-play text-white opacity-50 small"></i>';
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="p-2 ps-3 d-flex flex-column justify-content-center min-width-0 w-100">
                                                <strong class="text-dark text-truncate small mb-1 line-height-sm d-block" title="<?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <span class="text-secondary small d-block mb-0 text-truncate"><i class="fas fa-check-circle text-primary me-1 extra-small"></i><?= htmlspecialchars($rec['channel_title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="text-muted extra-small d-block mt-1 font-monospace"><?= date('M d, Y', strtotime($rec['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
        
        <?php
        // 5. Inject Async Interface Communications Engines
        $this->asset->addInlineStyle("
            .text-truncate-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .extra-small { font-size: 0.72rem; }
            .hover-card-lift { transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
            .hover-card-lift:hover { transform: translateY(-2px); box-shadow: 0 .4rem .8rem rgba(0,0,0,.06)!important; }
            .shadow-inner { box-shadow: inset 0 2px 4px rgba(0,0,0,0.06); }
            .pulse-slow { animation: audioPulse 3s infinite ease-in-out; }
            @keyframes audioPulse { 0%, 100% { transform: scale(1); opacity: 0.8; } 50% { transform: scale(1.08); opacity: 1; } }
            .custom-audio-player { border-radius: 50px; background: #f1f5f9; }
            .visual-filter-overlay { background: radial-gradient(circle, rgba(15,23,42,0.1) 0%, rgba(15,23,42,0.4) 100%); }
            .select-none { user-select: none; -webkit-user-select: none; }
        ");

        $this->asset->addInlineScript("
            const CSRF_TOKEN = '" . $this->session->get('_csrf_token') . "';
            const activeVideoId = " . (int)$videoId . ";

            // Dynamic view tracker fires 3 seconds following interface setup verification
            setTimeout(async () => {
                try {
                    const res = await fetch(`" . url('media/interact/view') . "?id=\${activeVideoId}`);
                    const data = await res.json();
                    if (data.success) {
                        const label = document.getElementById('live-views-label');
                        if (label) label.innerText = parseInt(label.innerText) + 1;
                    }
                } catch(e) {}
            }, 3000);

            // Transmit reaction preferences seamlessly
            async function sendVideoReaction(reactionType) {
                const payload = new URLSearchParams({ 
                    'video_id': activeVideoId, 
                    'type': reactionType,
                    '_csrf_token': CSRF_TOKEN // Include it in the body 
                });
                try {
                    const res = await fetch(`" . url('media/interact/reaction') . "`, { 
                        method: 'POST', 
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': CSRF_TOKEN // Optional: Good practice for security middle-wares
                        },
                        body: payload
                    });
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('likes-badge').innerText = data.likes_count;
                        document.getElementById('dislikes-badge').innerText = data.dislikes_count;
                    } else {
                        alert(data.error);
                    }
                } catch(e) { alert('Reaction sync engine communication failure.'); }
            }

            // Post comments instantly to thread
            async function submitUserCommentThread() {
                const inputNode = document.getElementById('user-comment-input');
                const cleanTxt = inputNode.value.trim();
                if (!cleanTxt) return;

                const payload = new URLSearchParams({ 
                    'video_id': activeVideoId, 
                    'comment_text': cleanTxt,
                    '_csrf_token': CSRF_TOKEN // Include it in the body
                });
                try {
                    const res = await fetch(`" . url('media/interact/comment') . "`, { 
                        method: 'POST', 
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': CSRF_TOKEN // Optional: Good practice for security middle-wares
                        },
                        body: payload 
                    });
                    const data = await res.json();
                    if (data.success) {
                        const wrapper = document.getElementById('dynamic-comments-wrapper');
                        
                        // Clear out placeholder card frame if it exists
                        const emptyNotice = document.getElementById('empty-comment-notice');
                        if (emptyNotice) emptyNotice.remove();

                        const block = document.createElement('div');
                        block.className = 'border-bottom pb-3 animate-fade-in';
                        block.innerHTML = `
                            <div class=\"d-flex justify-content-between align-items-center mb-1\">
                                <strong class=\"text-dark small\"><i class=\"far fa-user-circle text-primary me-1\"></i>\${data.username}</strong>
                                <span class=\"text-muted font-monospace extra-small\">\${data.date}</span>
                            </div>
                            <p class=\"m-0 text-secondary small ps-3\">\${data.comment.replace(/\\n/g, '<br>')}</p>
                        `;
                        wrapper.insertBefore(block, wrapper.firstChild);
                        inputNode.value = '';
                    } else {
                        alert(data.error);
                    }
                } catch(e) { alert('Failed to broadcast comment packet data.'); }
            }
        ");

        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }
}