<div class="admin-header mb-4">
    <h2 class="h4 mb-1">
        <i class="bi bi-person-plus me-2"></i>Add New User
    </h2>
    <p class="text-muted small mb-0">Create a new user account</p>
</div>

<div class="row">
    <div class="col-12 col-md-8">
        <div class="card">
            <div class="card-body">
                <form action="" method="post">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" 
                               value="<?= $view->e($old['username'] ?? '') ?>" required
                               autocomplete="username">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" 
                               value="<?= $view->e($old['email'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Display Name</label>
                        <input type="text" name="display_name" class="form-control" 
                               value="<?= $view->e($old['display_name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required
                               autocomplete="new-password">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirm" class="form-control" required
                               autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = ['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended'];
                            $selected = $old['status'] ?? 'active';
                            foreach ($statuses as $val => $label):
                            ?>
                            <option value="<?= $val ?>" <?= $selected === $val ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Email Verification</label>
                        <div class="form-check">
                            <input type="checkbox" name="email_verified" class="form-check-input" id="email_verified"
                                   <?= ($old['email_verified'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="email_verified">
                                Mark email as verified
                            </label>
                        </div>
                    </div>

                    <?php if (!empty($groups)): ?>
                    <h5 class="border-top pt-3 mb-3">Assign to Groups</h5>
                    <?php foreach ($groups as $g): ?>
                    <div class="form-check">
                        <input type="checkbox" name="groups[]" value="<?= $g['id'] ?>" 
                               class="form-check-input" id="group-<?= $g['id'] ?>"
                               <?= !empty($g['selected']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="group-<?= $g['id'] ?>">
                            <?= $view->e($g['name']) ?>
                            <?php if (!empty($g['is_system'])): ?>
                                <span class="badge bg-secondary ms-1">System</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?= $view->url->to('user/admin/users') ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>