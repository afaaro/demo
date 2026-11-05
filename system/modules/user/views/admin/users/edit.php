<div class="admin-header mb-4">
    <h2 class="h4 mb-1">
        <i class="bi bi-pencil-square me-2"></i>Edit User
    </h2>
    <p class="text-muted small mb-0">@<?= $view->e($user['username']) ?></p>
</div>

<div class="row">
    <div class="col-12 col-md-8">
        <div class="card mb-4">
            <div class="card-body">
                <form action="" method="post">
                    <div class="mb-3">
                        <label class="form-label">Display Name</label>
                        <input type="text" name="display_name" class="form-control" value="<?= $view->e($user['display_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= $view->e($user['email']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= $view->e($user['username']) ?>" disabled>
                        <small class="text-muted">Username cannot be changed.</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Status</label>
                        <select name="status" form="statusForm" class="form-select">
                            <?php foreach (['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $user['status'] === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h5 class="border-top pt-3 mb-3">Group Memberships</h5>
                    <?php foreach ($groups as $g): ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="group-<?= $g['id'] ?>"
                                <?= $g['selected'] ? 'checked' : '' ?> disabled>
                            <label class="form-check-label" for="group-<?= $g['id'] ?>">
                                <?= $view->e($g['name']) ?>
                                <?php if ($g['is_system']): ?>
                                    <span class="badge bg-secondary ms-1">System</span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?= $view->url->to('user/admin/users') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <!-- Status Form -->
        <form id="statusForm" action="<?= $view->url->to('user/admin/users/status') ?>" method="post" class="mb-3">
            <input type="hidden" name="id" value="<?= $user['id'] ?>">
            <button type="submit" class="btn btn-outline-secondary w-100">Update Status</button>
        </form>

        <!-- Danger Zone -->
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <i class="bi bi-exclamation-triangle me-1"></i>Danger Zone
            </div>
            <div class="card-body">
                <form action="<?= $view->url->to('user/admin/users/delete') ?>" method="post"
                      onsubmit="return confirm('Permanently delete this user? This cannot be undone!')">
                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-trash me-1"></i>Delete User
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>