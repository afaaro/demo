<?php

class MediaIndex extends Controller {
    public function indexAction(): void {
        // 1. Capture contextual category and query filters from the request stream
        $formatFilter = $this->request->get('format', 'str', 'all');
        $searchQuery  = $this->request->get('q', 'str', '');

        // 2. Map structural database query constraints safely
        $whereClause = "WHERE v.status = 'public'";
        $params = [];

        if ($formatFilter === 'audio') {
            $whereClause .= " AND v.file_path LIKE '%/audio/%'";
        } elseif ($formatFilter === 'images') {
            $whereClause .= " AND v.file_path LIKE '%/images/%'";
        } elseif ($formatFilter === 'video') {
            $whereClause .= " AND v.file_path NOT LIKE '%/audio/%' AND v.file_path NOT LIKE '%/images/%'";
        }

        if (!empty($searchQuery)) {
            $whereClause .= " AND (v.title LIKE ? OR c.title LIKE ?)";
            $params[] = '%' . $searchQuery . '%';
            $params[] = '%' . $searchQuery . '%';
        }

        // 3. Fetch feed items using a standard YouTube grid multiple (24 items)
        if (!empty($params)) {
            $feedItems = $this->db->query("
                SELECT v.*, c.title AS channel_title, c.avatar AS channel_avatar
                FROM `#__media_videos` v
                INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
                {$whereClause}
                ORDER BY v.created_at DESC
                LIMIT 24
            ", $params)->rows ?? [];
        } else {
            $feedItems = $this->db->query("
                SELECT v.*, c.title AS channel_title, c.avatar AS channel_avatar
                FROM `#__media_videos` v
                INNER JOIN `#__media_channels` c ON v.channel_id = c.channel_id
                {$whereClause}
                ORDER BY v.created_at DESC
                LIMIT 24
            ")->rows ?? [];
        }

        // Inject processing values explicitly into local view scope array properties
        $this->template('media/index', [
            'feedItems'    => $feedItems,
            'formatFilter' => $formatFilter,
            'searchQuery'  => $searchQuery
        ]);

        // Register visual page style logic assets
        $this->registerLayoutAssets();

        $this->panels->hideAll('left');
        $this->panels->hideAll('right');
        $this->load->view();
    }

    /**
     * Register view styling blocks and layout behavior scripts
     */
    private function registerLayoutAssets(): void {
        $this->asset->addInlineScript("
            function toggleYoutubeSidebar() {
                const drawer = document.getElementById('yt-sidebar-drawer');
                if (drawer) {
                    drawer.classList.toggle('collapsed-state');
                }
            }

            function executeYoutubeSearch() {
                const input = document.getElementById('yt-search-input');
                if (input) {
                    const query = encodeURIComponent(input.value.trim());
                    const currentUrl = new URL(window.location.href);
                    if (query) {
                        currentUrl.searchParams.set('q', query);
                    } else {
                        currentUrl.searchParams.delete('q');
                    }
                    window.location.href = currentUrl.toString();
                }
            }

            document.getElementById('yt-search-input')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    executeYoutubeSearch();
                }
            });
        ");

        $this->asset->addInlineStyle("
            .youtube-body-wrapper { font-family: Roboto, Arial, sans-serif; color: #0f172a; background: #ffffff; }
            .yt-header-navbar { height: 56px; max-height: 56px; z-index: 1050; }
            .max-width-600 { max-width: 600px; }
            .extra-mini { font-size: 0.68rem; }
            .font-weight-black { font-weight: 900 !important; }
            .px-0.5 { padding-left: 0.15rem; padding-right: 0.15rem; }
            .ms-0.5 { margin-left: 0.15rem !important; }
            .no-scrollbar::-webkit-scrollbar { display: none; }
            .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
            .object-cover { object-fit: cover; }
            
            /* Left Sidebar Navigation Drawer Standard Mechanics */
            .yt-navigation-drawer { width: 240px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); overflow-x: hidden; }
            .yt-navigation-drawer.collapsed-state { width: 72px; }
            .yt-navigation-drawer.collapsed-state .lbl-txt, 
            .yt-navigation-drawer.collapsed-state .extra-mini,
            .yt-navigation-drawer.collapsed-state hr { display: none !important; }
            .yt-navigation-drawer.collapsed-state .sidebar-row-link { justify-content: center; padding: 0.75rem; text-align: center; }
            .yt-navigation-drawer.collapsed-state .sidebar-row-link .menu-icon { margin: 0 !important; font-size: 1.25rem; }
            
            .sidebar-row-link { display: flex; align-items: center; padding: 0.5rem 1rem; border-radius: 10px; color: #0f172a; text-decoration: none; font-size: 0.88rem; font-weight: 400; margin-bottom: 2px; }
            .sidebar-row-link:hover { background-color: rgba(0,0,0,0.05); color: #000000; }
            .sidebar-row-link.active { background-color: rgba(0,0,0,0.05); font-weight: 700; }
            .sidebar-row-link .menu-icon { width: 24px; margin-right: 1.25rem; font-size: 1.1rem; text-align: center; }
            
            /* Custom Responsive Top Header Filters Recommendations Pills Styles */
            .sticky-pill-bar { position: sticky; top: 56px; z-index: 1000; }
            .yt-pill-filter { background-color: rgba(0,0,0,0.05); color: #0f172a; font-size: 0.86rem; font-weight: 500; padding: 0.35rem 0.75rem; border-radius: 8px; text-decoration: none; white-space: nowrap; border: 1px solid transparent; transition: background 0.15s; }
            .yt-pill-filter:hover { background-color: rgba(0,0,0,0.1); }
            .yt-pill-filter.active { background-color: #0f172a; color: #ffffff; font-weight: 600; }
            
            /* YouTube Grid Interaction Scaling Frame Engine rules */
            .yt-video-card-anchor { display: block; width: 100%; margin-bottom: 0.5rem; }
            .thumbnail-container { box-shadow: none !important; }
            .card-hitbox-overlay { position: absolute; top:0; left:0; width:100%; height:100%; z-index: 4; }
            .scaling-target { transform: scale(1); transition: transform 0.25s ease-in-out; }
            
            .yt-video-card-anchor:hover .scaling-target { transform: scale(1.03); }
            .yt-video-card-anchor:hover .rounded-3 { border-radius: 0px !important; }
            
            /* Exact Typography Configuration for Multi-Line Clamping Layout logic */
            .yt-title-text-clamped { font-size: 0.89rem; font-weight: 600; line-height: 1.4; color: #0f172a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-height: 2.8em; }
            .yt-channel-link { font-size: 0.78rem; color: #606060; font-weight: 400; position: relative; z-index: 6; }
            .yt-channel-link:hover { color: #0f172a; }
            .verified-check-icon { font-size: 0.72rem; color: #606060; }
            .yt-card-metadata { font-size: 0.78rem; color: #606060; font-weight: 400; margin-top: 1px; }
            
            /* Relative Bottom Right Time stamp box badge block decoration style */
            .yt-time-badge { font-size: 0.72rem; font-weight: 600; padding: 1px 4px; border-radius: 4px; background-color: rgba(0,0,0,0.8); color: #ffffff; letter-spacing: 0.02em; }
            .search-container-group .form-control:focus { border-color: #cbd5e1 !important; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05) !important; background: #ffffff; }
        ");
    }
}