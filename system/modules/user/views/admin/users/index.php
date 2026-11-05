<div class="admin-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 mb-1">
            <i class="bi bi-people me-2"></i>User Management
        </h2>
        <p class="text-muted small mb-0">
            <?= $view->e($total) ?> user<?= $total !== 1 ? 's' : '' ?> total
        </p>
    </div>
    <a href="<?= $view->url->to('user/admin/users/add') ?>" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>Add New User
    </a>
</div>

<!-- Search -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form action="<?= $view->url->to('user/admin/users/search') ?>" method="get" class="d-flex gap-2">
            <input type="text" name="q" class="form-control" placeholder="Search by name, email..." 
                   value="<?= $view->e($q ?? '') ?>" required>
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if (!empty($q)): ?>
                <a href="<?= $view->url->to('user/admin/users') ?>" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
            <p class="text-muted text-center py-4 mb-0">No users found.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Groups</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <strong><?= $view->e($u['display_name'] ?: $u['username']) ?></strong>
                                    <div class="text-muted small">@<?= $view->e($u['username']) ?></div>
                                </td>
                                <td class="text-break small"><?= $view->e($u['email']) ?></td>
                                <td class="small text-muted">
                                    <?= $view->e($u['groups'] ?: '—') ?>
                                </td>
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
                                <td class="text-muted small">
                                    <?= $view->e(date('M j, Y', strtotime($u['created_at']))) ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= $view->url->to('user/admin/users/edit', ['id' => $u['id']]) ?>" 
                                       class="btn btn-sm btn-outline-primary">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= \System\Library\Paginator::make((int) ($total ?? 0), (int) ($page ?? 1), (int) ($perPage ?? 20))->render(
    static fn (int $p): string => $view->url->to('user/admin/users', ['page' => $p]),
    ['theme' => 'bootstrap', 'class' => 'justify-content-center']
) ?>