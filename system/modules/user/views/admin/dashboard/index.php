<div class="admin-header mb-4">
    <h2 class="h4 mb-1">
        <i class="bi bi-speedometer2 me-2"></i>Admin Dashboard
    </h2>
    <p class="text-muted small mb-0">
        Welcome, <strong><?= $view->e($user->displayName) ?></strong>
    </p>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="h2 fw-bold text-primary mb-1"><?= $view->e($stats['total_users']) ?></h3>
                <p class="card-text text-muted small">Total Users</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="h2 fw-bold text-success mb-1"><?= $view->e($stats['active_users']) ?></h3>
                <p class="card-text text-muted small">Active</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="h2 fw-bold text-info mb-1"><?= $view->e($stats['new_today']) ?></h3>
                <p class="card-text text-muted small">New Today</p>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="h2 fw-bold text-secondary mb-1"><?= $view->e($stats['total_groups']) ?></h3>
                <p class="card-text text-muted small">Groups</p>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h5>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-sm-6 col-md-4">
                <a href="<?= $view->url->to('user/register') ?>" class="btn btn-primary w-100">
                    <i class="bi bi-person-plus me-2"></i>Create User
                </a>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <a href="<?= $view->url->to('user/admin/groups') ?>" class="btn btn-success w-100">
                    <i class="bi bi-shield-gear me-2"></i>Manage Groups
                </a>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <button class="btn btn-outline-secondary w-100" disabled>
                    <i class="bi bi-gear me-2"></i>System Settings
                    <span class="badge bg-secondary ms-1">Soon</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Recent Registrations -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="bi bi-clock-history me-2"></i>Recent Registrations
        </h5>
        <span class="text-muted small">Last 10</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentUsers)): ?>
            <p class="text-muted text-center py-3 mb-0">No users registered yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Verified</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $u): ?>
                            <tr>
                                <td>
                                    <strong><?= $view->e($u['display_name'] ?: $u['username']) ?></strong>
                                    <div class="text-muted small">@<?= $view->e($u['username']) ?></div>
                                </td>
                                <td class="text-break"><?= $view->e($u['email']) ?></td>
                                <td>
                                    <?php
                                    $statusClass = match($u['status']) {
                                        'active'    => 'bg-success',
                                        'pending'   => 'bg-warning text-dark',
                                        'suspended' => 'bg-danger',
                                        default     => 'bg-secondary'
                                    };
                                    ?>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= $view->e(ucfirst($u['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $u['email_verified'] 
                                        ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>' 
                                        : '<span class="text-muted"><i class="bi bi-dash-circle"></i></span>' 
                                    ?>
                                </td>
                                <td class="text-muted small">
                                    <?= $view->e(date('M j, Y g:i A', strtotime($u['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Main Cards -->
<div class="row g-3">
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="card h-100 border-primary">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-people text-primary me-2"></i>Users
                </h5>
                <p class="card-text text-muted small">Manage registered accounts</p>
                <a href="<?= $view->url->to('user/groups/all') ?>" class="btn btn-sm btn-primary">
                    View All Groups
                </a>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="card h-100 border-success">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-shield-lock text-success me-2"></i>Permissions
                </h5>
                <p class="card-text text-muted small">Group access rules</p>
                <span class="text-muted small">Coming soon</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="card h-100 border-info">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-gear text-info me-2"></i>System
                </h5>
                <p class="card-text text-muted small">Site configuration</p>
                <span class="text-muted small">Coming soon</span>
            </div>
        </div>
    </div>
</div>