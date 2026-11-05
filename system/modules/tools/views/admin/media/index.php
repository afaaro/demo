<?php
/** @var System\Library\View $view */
/** @var array{count:int,bytes:int,images:int} $stats */
/** @var string $pickerUrl */
/** @var string $uploadUrl */
/** @var string $deleteUrl */
/** @var string $bulkUrl */
/** @var string $cleanupUrl */
/** @var string $csrf */
/** @var string $csrfField */
/** @var int $maxBytes */

use ToolsAdminMedia as MediaAdmin;
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Media library</h1>
            <p class="text-muted mb-0">Upload, browse, and manage images used across the site.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-primary" id="media-upload-toggle">
                <i class="bi bi-upload me-1"></i>Upload
            </button>
            <form method="post" action="<?= $view->e($cleanupUrl) ?>" class="d-inline"
                  onsubmit="return confirm('Remove DB rows with missing files and orphan files under uploads/?');">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Cleanup orphans</button>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold" id="media-stat-count"><?= (int) $stats['count'] ?></div>
                <div class="small text-muted">Items</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold"><?= $view->e(MediaAdmin::formatBytes((int) $stats['bytes'])) ?></div>
                <div class="small text-muted">Total size</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="fs-4 fw-semibold"><?= (int) $stats['images'] ?></div>
                <div class="small text-muted">Images</div>
            </div>
        </div>
    </div>

    <div id="media-upload-panel" class="border rounded p-3 mb-4 bg-body-tertiary" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Upload images</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="media-upload-cancel">Cancel</button>
        </div>
        <p class="small text-muted mb-2">
            JPG, PNG, GIF, WEBP · max <?= $view->e(MediaAdmin::formatBytes($maxBytes)) ?> each · multiple files allowed
        </p>
        <div id="media-dropzone" class="border border-2 border-dashed rounded p-4 text-center mb-2"
             style="cursor:pointer; min-height:120px;">
            <div class="text-muted">Drop files here or click to choose</div>
            <input type="file" id="media-file-input" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
        </div>
        <div id="media-upload-status" class="small text-muted"></div>
    </div>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label small mb-1" for="media-search">Search</label>
            <input type="search" id="media-search" class="form-control" placeholder="Filename…" autocomplete="off">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="media-mime">Type</label>
            <select id="media-mime" class="form-select">
                <option value="">All types</option>
                <option value="image">Images only</option>
                <option value="image/jpeg">JPEG</option>
                <option value="image/png">PNG</option>
                <option value="image/gif">GIF</option>
                <option value="image/webp">WEBP</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1" for="media-sort">Sort</label>
            <select id="media-sort" class="form-select">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="name">Name (A–Z)</option>
                <option value="size">Largest first</option>
            </select>
        </div>
        <div class="col-md-4 text-md-end">
            <button type="button" class="btn btn-outline-danger btn-sm" id="media-bulk-delete" disabled>
                Delete selected (<span id="media-selected-count">0</span>)
            </button>
        </div>
    </div>

    <div id="media-grid"
         data-picker-url="<?= $view->e($pickerUrl) ?>"
         data-upload-url="<?= $view->e($uploadUrl) ?>"
         data-delete-url="<?= $view->e($deleteUrl) ?>"
         data-bulk-url="<?= $view->e($bulkUrl) ?>"
         data-csrf="<?= $view->e($csrf) ?>">
        <p class="text-muted">Loading…</p>
    </div>
    <nav id="media-pagination" class="mt-3"></nav>
</div>

<script>
(function () {
    var grid = document.getElementById('media-grid');
    var pagination = document.getElementById('media-pagination');
    var search = document.getElementById('media-search');
    var mimeSel = document.getElementById('media-mime');
    var sortSel = document.getElementById('media-sort');
    var bulkBtn = document.getElementById('media-bulk-delete');
    var selectedCount = document.getElementById('media-selected-count');
    var uploadToggle = document.getElementById('media-upload-toggle');
    var uploadPanel = document.getElementById('media-upload-panel');
    var uploadCancel = document.getElementById('media-upload-cancel');
    var dropzone = document.getElementById('media-dropzone');
    var fileInput = document.getElementById('media-file-input');
    var uploadStatus = document.getElementById('media-upload-status');
    var statCount = document.getElementById('media-stat-count');

    if (!grid) return;

    var pickerUrl = grid.dataset.pickerUrl;
    var uploadUrl = grid.dataset.uploadUrl;
    var deleteUrl = grid.dataset.deleteUrl;
    var bulkUrl = grid.dataset.bulkUrl;
    var csrf = grid.dataset.csrf;
    var perPage = 24;
    var state = { page: 1, q: '', mime: '', sort: 'newest' };
    var selected = {};
    var searchTimer = null;

    function formatBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function updateBulkUi() {
        var n = Object.keys(selected).length;
        selectedCount.textContent = String(n);
        bulkBtn.disabled = n === 0;
    }

    function renderGrid(data) {
        if (!data.items || data.items.length === 0) {
            grid.innerHTML = '<div class="alert alert-info mb-0">No media found.</div>';
            pagination.innerHTML = '';
            return;
        }

        if (statCount && typeof data.total === 'number') {
            statCount.textContent = String(data.total);
        }

        var html = '<div class="row g-3">';
        data.items.forEach(function (item) {
            var checked = selected[item.id] ? ' checked' : '';
            html += '<div class="col-6 col-md-4 col-lg-3" data-id="' + item.id + '">';
            html += '<div class="border rounded h-100 overflow-hidden bg-body">';
            html += '<div class="position-relative">';
            html += '<img src="' + escapeHtml(item.url) + '" alt="" loading="lazy"'
                + ' style="height:150px;width:100%;object-fit:cover;display:block;"'
                + ' onerror="this.style.opacity=.25">';
            html += '<div class="form-check position-absolute top-0 start-0 m-2">'
                + '<input class="form-check-input media-select" type="checkbox" value="' + item.id + '"' + checked + '>'
                + '</div>';
            html += '</div>';
            html += '<div class="p-2">';
            html += '<div class="small text-truncate fw-semibold" title="' + escapeHtml(item.filename) + '">'
                + escapeHtml(item.filename) + '</div>';
            html += '<div class="text-muted small">'
                + item.width + '×' + item.height + ' · ' + formatBytes(item.size) + '</div>';
            html += '<div class="text-muted small">' + escapeHtml(item.created_at) + '</div>';
            html += '<div class="d-flex gap-1 mt-2">';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-copy="'
                + escapeHtml(item.url) + '">Copy URL</button>';
            html += '<button type="button" class="btn btn-sm btn-outline-danger media-delete" data-id="'
                + item.id + '" data-name="' + escapeHtml(item.filename) + '">Delete</button>';
            html += '</div></div></div></div>';
        });
        html += '</div>';
        grid.innerHTML = html;
    }

    function renderPagination(data) {
        if (!data.pages || data.pages <= 1) {
            pagination.innerHTML = '';
            return;
        }
        var html = '<ul class="pagination pagination-sm mb-0">';
        html += '<li class="page-item' + (data.page <= 1 ? ' disabled' : '') + '">'
            + '<a class="page-link" href="#" data-page="' + (data.page - 1) + '">Prev</a></li>';
        var start = Math.max(1, data.page - 2);
        var end = Math.min(data.pages, data.page + 2);
        for (var p = start; p <= end; p++) {
            html += '<li class="page-item' + (p === data.page ? ' active' : '') + '">'
                + '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
        }
        html += '<li class="page-item' + (data.page >= data.pages ? ' disabled' : '') + '">'
            + '<a class="page-link" href="#" data-page="' + (data.page + 1) + '">Next</a></li>';
        html += '</ul>';
        html += '<div class="small text-muted mt-1">' + data.total + ' item(s)</div>';
        pagination.innerHTML = html;
    }

    function load() {
        var url = new URL(pickerUrl, window.location.origin);
        url.searchParams.set('page', state.page);
        url.searchParams.set('per_page', perPage);
        if (state.q) url.searchParams.set('q', state.q);
        if (state.mime) url.searchParams.set('mime', state.mime);
        if (state.sort) url.searchParams.set('sort', state.sort);

        grid.innerHTML = '<p class="text-muted">Loading…</p>';

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderGrid(data);
                renderPagination(data);
                updateBulkUi();
            })
            .catch(function () {
                grid.innerHTML = '<div class="alert alert-danger">Could not load media.</div>';
            });
    }

    function uploadFiles(fileList) {
        if (!fileList || !fileList.length) return;
        var form = new FormData();
        Array.prototype.forEach.call(fileList, function (f) {
            form.append('files[]', f);
        });
        uploadStatus.textContent = 'Uploading ' + fileList.length + ' file(s)…';

        fetch(uploadUrl, {
            method: 'POST',
            headers: { 'X-CSRF-Token-Media': csrf, 'Accept': 'application/json' },
            body: form
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            if (!res.body || !res.body.ok) {
                uploadStatus.textContent = (res.body && res.body.error) ? res.body.error : 'Upload failed.';
                return;
            }
            var msg = 'Uploaded ' + (res.body.count || 0) + ' file(s).';
            if (res.body.errors && res.body.errors.length) {
                msg += ' Some failed: ' + res.body.errors.slice(0, 2).join(' ');
            }
            uploadStatus.textContent = msg;
            state.page = 1;
            selected = {};
            load();
        })
        .catch(function () {
            uploadStatus.textContent = 'Network error during upload.';
        });
    }

    uploadToggle.addEventListener('click', function () {
        uploadPanel.style.display = uploadPanel.style.display === 'none' ? 'block' : 'none';
    });
    uploadCancel.addEventListener('click', function () {
        uploadPanel.style.display = 'none';
        uploadStatus.textContent = '';
    });
    dropzone.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        uploadFiles(fileInput.files);
        fileInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropzone.classList.add('border-primary');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            dropzone.classList.remove('border-primary');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        uploadFiles(e.dataTransfer.files);
    });

    search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.q = search.value.trim();
            state.page = 1;
            load();
        }, 250);
    });
    mimeSel.addEventListener('change', function () {
        state.mime = mimeSel.value;
        state.page = 1;
        load();
    });
    sortSel.addEventListener('change', function () {
        state.sort = sortSel.value;
        state.page = 1;
        load();
    });

    pagination.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-page]');
        if (!a) return;
        e.preventDefault();
        var p = parseInt(a.dataset.page, 10);
        if (!isNaN(p) && p > 0) {
            state.page = p;
            load();
        }
    });

    grid.addEventListener('change', function (e) {
        var cb = e.target.closest('.media-select');
        if (!cb) return;
        if (cb.checked) selected[cb.value] = true;
        else delete selected[cb.value];
        updateBulkUi();
    });

    grid.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('[data-copy]');
        if (copyBtn) {
            var text = copyBtn.dataset.copy;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    var orig = copyBtn.textContent;
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () { copyBtn.textContent = orig; }, 1200);
                });
            }
            return;
        }

        var del = e.target.closest('.media-delete');
        if (!del) return;
        if (!confirm('Delete "' + del.dataset.name + '"? This cannot be undone.')) return;

        var form = new FormData();
        form.append('id', del.dataset.id);
        fetch(deleteUrl, {
            method: 'POST',
            headers: { 'X-CSRF-Token-Media': csrf, 'Accept': 'application/json' },
            body: form
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                delete selected[del.dataset.id];
                load();
            } else {
                alert((res && res.error) ? res.error : 'Delete failed.');
            }
        })
        .catch(function () { alert('Network error during delete.'); });
    });

    bulkBtn.addEventListener('click', function () {
        var ids = Object.keys(selected);
        if (!ids.length) return;
        if (!confirm('Delete ' + ids.length + ' selected item(s)?')) return;

        var form = new FormData();
        ids.forEach(function (id) { form.append('ids[]', id); });
        fetch(bulkUrl, {
            method: 'POST',
            headers: { 'X-CSRF-Token-Media': csrf, 'Accept': 'application/json' },
            body: form
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                selected = {};
                updateBulkUi();
                load();
            } else {
                alert((res && res.error) ? res.error : 'Bulk delete failed.');
            }
        })
        .catch(function () { alert('Network error during bulk delete.'); });
    });

    load();
})();
</script>
