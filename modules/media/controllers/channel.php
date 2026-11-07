<?php

class MediaChannel extends Controller {

    /**
     * Public Creator Channel Profile Canvas
     * Route: /media/channel?id=CHANNEL_ID
     */
    public function indexAction(): void {
        $channelId = $this->request->get('id', 'int');

        if (empty($channelId)) {
            $this->notify->error('Invalid creator channel parameter context.');
            redirect('media');
        }

        // 1. Fetch Channel Metadata Profile Information
        $channel = $this->db->query("
            SELECT * FROM `#__media_channels` 
            WHERE `channel_id` = ? 
            LIMIT 1
        ", [$channelId])->row;

        if (empty($channel)) {
            $this->notify->error('The requested creator channel space does not exist on this platform.');
            redirect('media');
        }

        // 2. Extract Publicly Accessible Content Assets Ledger
        $page = $this->request->get('page', 'int', 1);
        $limit = 12; // Standard 12-item grid alignment
        $offset = ($page - 1) * $limit;

        $videos = $this->db->query("
            SELECT `video_id`, `title`, `file_path`, `thumbnail_path`, `created_at`
            FROM `#__media_videos`
            WHERE `channel_id` = ? AND `status` = 'public'
            ORDER BY `created_at` DESC
            LIMIT " . (int)$offset . ", " . (int)$limit . "
        ", [$channelId])->rows ?? [];

        // Count total matching rows for pagination render logic
        $totalVideosCount = $this->db->query("
            SELECT COUNT(*) AS total 
            FROM `#__media_videos` 
            WHERE `channel_id` = ? AND `status` = 'public'
        ", [$channelId])->row['total'] ?? 0;

        $totalPages = ceil($totalVideosCount / $limit);

        // Inject page style specifications directly into the framework assets manager
        $this->registerLayoutAssets();

        // 3. Render Channel Profile Layout Presentation View using framework engine
        $this->template('media/channel', [
            'channel'          => $channel,
            'videos'           => $videos,
            'channelId'        => $channelId,
            'page'             => $page,
            'totalPages'       => $totalPages,
            'totalVideosCount' => $totalVideosCount
        ]);

        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    /**
     * Register channel styling overrides safely outside view markup files
     */
    private function registerLayoutAssets(): void {
        $this->asset->addInlineStyle("
            .extra-small { font-size: 0.78rem; }
            .bg-gradient-dark { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); }
            .gradient-overlay { background: radial-gradient(circle, rgba(30,41,59,0.3) 0%, rgba(15,23,42,0.7) 100%); transition: opacity 0.2s ease; }
            .group-hover-lift { transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
            .group-hover-lift:hover { transform: translateY(-3px); }
            .group-hover-lift:hover .scale-hover { transform: scale(1.15); }
            .z-index-1 { z-index: 1; }
        ");
    }
}