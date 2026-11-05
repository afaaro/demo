<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserAdminDashboard extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            $this->session->set('redirect_after_login', $this->url->to('admin/dashboard'));
            redirect('user/login');
        }
        // Permissions: Action.php only (user.admin.dashboard.*).
    }

    public function indexAction(): void
    {
        $user = $this->auth->user();
        $db = $this->registry->get('db');

        // Calculate stats
        $stats = [
            'total_users'  => (int) $db->query("SELECT COUNT(*) FROM #__users")->column(),
            'active_users' => (int) $db->query("SELECT COUNT(*) FROM #__users WHERE status = 'active'")->column(),
            'new_today'    => (int) $db->query(
                "SELECT COUNT(*) FROM #__users WHERE DATE(created_at) = CURDATE()"
            )->column(),
            'total_groups' => (int) $db->query("SELECT COUNT(*) FROM #__user_groups")->column(),
        ];

        // Recent registrations — last 10 newest users
        $recentUsers = $db->query("
            SELECT id, username, email, display_name, status, email_verified, created_at
            FROM #__users
            ORDER BY created_at DESC
            LIMIT 10
        ")->all();

        echo $this->output('index', [
            'title'       => 'Admin Dashboard',
            'user'        => $user,
            'stats'       => $stats,
            'recentUsers' => $recentUsers,
            'isAdmin'     => true,
            'pageClass'   => 'admin-dashboard',
        ]);
    }
}