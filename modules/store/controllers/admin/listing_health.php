<?php
declare(strict_types=1);

use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\InventoryService;
use Module\Store\Services\ListingRepository;
use Module\Store\Services\ListingSyncService;
use Module\Store\Services\ListingTemplateRepository;
use Module\Store\Services\ProductRepository;
use Module\Store\Services\SyncLogRepository;
use Module\Store\Services\VariantRepository;
use System\Engine\Controller;

class StoreAdminListingHealth extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
    }

    public function indexAction(): void
    {
        $issues = (new ListingRepository($this->db))->healthIssues(250);
        $counts = ['error' => 0, 'ended' => 0, 'qty_mismatch' => 0];
        foreach ($issues as $row) {
            $reason = (string) ($row['reason'] ?? '');
            if (isset($counts[$reason])) {
                $counts[$reason]++;
            }
        }

        echo $this->view->inline(function () use ($issues, $counts) {
            $this->view->assign('title', 'Listing health');
            $this->view->block('right_sidebar', 'Products');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
            echo '<div><h1 class="h3 mb-1"><i class="bi bi-clipboard2-pulse me-1"></i>Listing health</h1>'
               . '<p class="text-muted small mb-0">'
               . (int) $counts['error'] . ' errors · '
               . (int) $counts['ended'] . ' ended · '
               . (int) $counts['qty_mismatch'] . ' qty mismatches</p></div>';
            echo '<a class="btn btn-outline-secondary btn-sm" href="'
               . $e($this->url->to('store/admin/channels')) . '">Channels</a>';
            echo '</div>';

            if ($issues === []) {
                echo '<div class="alert alert-success">No ended, error, or qty-mismatch listings.</div>';
                return;
            }

            echo '<form method="post" action="'
               . $e($this->url->to('store/admin/listing-health/bulk')) . '" id="lh-bulk-form">';
            echo $this->request->csrfField();
            echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
            echo '<button type="submit" name="bulk_action" value="repush" class="btn btn-sm btn-primary"'
               . ' onclick="return confirm(\'Re-push selected listings?\');">Re-push selected</button>';
            echo '<button type="submit" name="bulk_action" value="sync_qty" class="btn btn-sm btn-outline-primary"'
               . ' onclick="return confirm(\'Sync qty for selected listings?\');">Sync qty selected</button>';
            echo '<span class="small text-muted" id="lh-selected-count">0 selected</span>';
            echo '</div>';

            echo '<div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white border">';
            echo '<thead class="table-light"><tr>'
               . '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="lh-check-all"></th>'
               . '<th>Product</th><th>SKU</th><th>Channel</th><th>Status</th>'
               . '<th>Reason</th><th class="text-end">Available</th><th class="text-end">Buffer</th>'
               . '<th>Error</th><th></th>'
               . '</tr></thead><tbody>';
            foreach ($issues as $row) {
                $l = $row['listing'];
                $lid = (int) ($l->id ?? 0);
                $reasonBadge = match ($row['reason']) {
                    'error' => 'text-bg-danger',
                    'ended' => 'text-bg-secondary',
                    'qty_mismatch' => 'text-bg-warning',
                    default => 'text-bg-light',
                };
                echo '<tr>';
                echo '<td><input type="checkbox" class="form-check-input lh-row" name="listing_ids[]" value="'
                   . $lid . '"></td>';
                echo '<td>';
                if ($row['product_id'] > 0) {
                    echo '<a href="' . $e($this->url->to('store/admin/products/edit', ['id' => $row['product_id']])) . '">'
                       . $e($row['product_name'] !== '' ? $row['product_name'] : 'Product #' . $row['product_id'])
                       . '</a>';
                } else {
                    echo $e($row['product_name'] !== '' ? $row['product_name'] : '—');
                }
                echo '</td>';
                echo '<td class="small"><code>' . $e($row['variant_sku']) . '</code></td>';
                echo '<td class="small">' . $e($row['channel_label']) . '</td>';
                echo '<td><span class="badge text-bg-secondary">' . $e($l->status) . '</span></td>';
                echo '<td><span class="badge ' . $reasonBadge . '">' . $e($row['reason']) . '</span></td>';
                echo '<td class="text-end">' . (int) $row['available'] . '</td>';
                echo '<td class="text-end" style="width:6rem">'
                   . '<input type="number" min="0" class="form-control form-control-sm text-end"'
                   . ' name="qty_buffer[' . $lid . ']" value="' . (int) ($l->qtyBuffer ?? 0) . '"></td>';
                echo '<td class="small text-muted">' . $e($l->lastError !== '' ? $l->lastError : '—') . '</td>';
                echo '<td class="text-end text-nowrap">';
                echo '<button type="submit" name="bulk_action" value="save_buffer" class="btn btn-sm btn-outline-primary"'
                   . ' formaction="' . $e($this->url->to('store/admin/listing-health/bulk')) . '">Save</button> ';
                if ($l->variantId) {
                    echo '<a class="btn btn-sm btn-outline-secondary" href="'
                       . $e($this->url->to('store/admin/inventory/adjust', ['id' => (int) $l->variantId]))
                       . '">Stock</a>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div></form>';

            echo <<<'JS'
<script>
(function () {
  const boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.lh-row')); };
  const countEl = document.getElementById('lh-selected-count');
  const all = document.getElementById('lh-check-all');
  function sync() {
    const list = boxes();
    const n = list.filter(function (c) { return c.checked; }).length;
    if (countEl) countEl.textContent = n + ' selected';
    if (all) {
      all.checked = list.length > 0 && n === list.length;
      all.indeterminate = n > 0 && n < list.length;
    }
  }
  if (all) all.addEventListener('change', function () {
    boxes().forEach(function (c) { c.checked = all.checked; });
    sync();
  });
  boxes().forEach(function (c) { c.addEventListener('change', sync); });
  sync();
})();
</script>
JS;
        });
    }

    public function bulkAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('store/admin/listing-health');
            return;
        }
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please reload.');
            redirect_to('store/admin/listing-health');
            return;
        }

        $action = trim((string) $this->request->post('bulk_action', 'string', ''));
        $listings = new ListingRepository($this->db);

        if ($action === 'save_buffer') {
            $buffers = $this->request->post('qty_buffer', 'raw', []);
            if (!is_array($buffers)) {
                $buffers = [];
            }
            $saved = 0;
            foreach ($buffers as $listingId => $buf) {
                $listingId = (int) $listingId;
                if ($listingId <= 0) {
                    continue;
                }
                $listings->updateFields($listingId, ['qty_buffer' => max(0, (int) $buf)]);
                $saved++;
            }
            $this->notify->success('Updated qty buffer on ' . $saved . ' listing(s).');
            redirect_to('store/admin/listing-health');
            return;
        }

        $rawIds = $this->request->post('listing_ids', 'raw', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $ids = array_values(array_filter(array_map('intval', $rawIds), static fn(int $id) => $id > 0));
        if ($ids === [] || !in_array($action, ['repush', 'sync_qty'], true)) {
            $this->notify->error('Select at least one listing and a bulk action.');
            redirect_to('store/admin/listing-health');
            return;
        }

        $channels = new ChannelRepository($this->db);
        $vault = CredentialsVault::forTenant((string) $this->config->get('store.credentials_key', ''));
        $sync = new ListingSyncService(
            $this->db,
            $channels,
            new ChannelClientFactory($vault, $channels),
            $listings,
            new VariantRepository($this->db),
            new ProductRepository($this->db),
            new InventoryService($this->db),
            new ListingTemplateRepository($this->db),
        );
        $log = new SyncLogRepository($this->db);
        $ok = 0;
        $fail = 0;

        foreach ($ids as $listingId) {
            $listing = $listings->findById($listingId);
            if ($listing === null || !(int) ($listing->variantId ?? 0)) {
                $fail++;
                continue;
            }
            $channel = $channels->find((int) $listing->channelId);
            if ($channel === null || !$channel->isConnected()) {
                $fail++;
                continue;
            }
            try {
                if ($action === 'repush') {
                    $sync->pushVariant($channel, (int) $listing->variantId);
                    $log->log((int) $channel->id, 'listing_repush', 'ok', 'Re-pushed listing #' . $listingId, [
                        'listing_id' => $listingId,
                        'variant_id' => (int) $listing->variantId,
                    ], $this->auth->id(), '');
                } else {
                    $sync->syncInventory($channel, (int) $listing->variantId);
                    $log->log((int) $channel->id, 'listing_sync_qty', 'ok', 'Synced qty for listing #' . $listingId, [
                        'listing_id' => $listingId,
                        'variant_id' => (int) $listing->variantId,
                    ], $this->auth->id(), '');
                }
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                try {
                    $log->log((int) $channel->id, $action === 'repush' ? 'listing_repush' : 'listing_sync_qty', 'error', $e->getMessage(), [
                        'listing_id' => $listingId,
                    ], $this->auth->id(), '');
                } catch (\Throwable) {
                }
            }
        }

        $this->notify->success($ok . ' succeeded' . ($fail > 0 ? ', ' . $fail . ' failed' : '') . '.');
        redirect_to('store/admin/listing-health');
    }
}
