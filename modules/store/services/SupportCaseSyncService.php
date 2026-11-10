<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Channels\Marketplace\Amazon\AmazonChannelClient;
use Module\Store\Services\Channels\Marketplace\Bnq\BnqChannelClient;
use Module\Store\Services\Channels\Marketplace\Ebay\EbayChannelClient;
use RuntimeException;
use System\Library\Database;

final class SupportCaseSyncService
{
    /** Statuses that skip full-detail GET (closed / terminal). */
    private const CLOSED_STATUSES = [
        'CLOSED',
        'CS_CLOSED',
        'OTHER',
        'CLOSED_WITH_ESCALATION',
    ];

    public function __construct(
        private Database $db,
        private ChannelClientFactory $factory,
        private SupportCaseRepository $cases,
    ) {}

    /**
     * @return array{created: int, updated: int, cases: int, inquiries: int, details: int, errors: int, returns?: int, atoz?: int, safet?: int}
     */
    public function syncChannel(Channel $channel, int $lookbackDays = 90): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return [
                'created' => 0, 'updated' => 0, 'cases' => 0, 'inquiries' => 0,
                'details' => 0, 'errors' => 0,
            ];
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        if ($channel->type === 'amazon') {
            return $this->syncAmazon($channel, $lookbackDays);
        }

        if ($channel->type === 'bnq') {
            return $this->syncBnq($channel);
        }

        if ($channel->type !== 'ebay') {
            throw new RuntimeException('Support cases are only available for eBay, Amazon, and B&Q channels.');
        }

        $client = $this->factory->make($channel);
        if (!$client instanceof EbayChannelClient) {
            throw new RuntimeException('eBay client unavailable.');
        }
        if (!method_exists($client, 'searchSupportCases')) {
            throw new RuntimeException('This eBay client does not support case search.');
        }

        $stats = [
            'created'   => 0,
            'updated'   => 0,
            'cases'     => 0,
            'inquiries' => 0,
            'details'   => 0,
            'errors'    => 0,
        ];

        // CaseStatusFilter values from Post-Order API
        foreach (['OPEN', 'WAITING_CS', 'WAITING_DELIVERY', 'ON_HOLD', 'CLOSED', 'CS_CLOSED'] as $status) {
            try {
                $this->pullCases($client, (int) $channel->id, $status, $lookbackDays, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('eBay case sync [' . $status . ']: ' . $e->getMessage());
            }
        }

        // InquiryStatusFilter values
        foreach (['OPEN', 'WAITING_SELLER_RESPONSE', 'WAITING_BUYER_RESPONSE', 'CLOSED', 'CLOSED_WITH_ESCALATION', 'PENDING'] as $status) {
            try {
                $this->pullInquiries($client, (int) $channel->id, $status, $lookbackDays, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('eBay inquiry sync [' . $status . ']: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Amazon MFN returns report + Finances A-to-Z / SAFE-T events.
     *
     * @return array{created: int, updated: int, cases: int, inquiries: int, details: int, errors: int, returns: int, atoz: int, safet: int}
     */
    private function syncAmazon(Channel $channel, int $lookbackDays): array
    {
        $client = $this->factory->make($channel);
        if (!$client instanceof AmazonChannelClient) {
            throw new RuntimeException('Amazon client unavailable.');
        }

        $stats = [
            'created'   => 0,
            'updated'   => 0,
            'cases'     => 0,
            'inquiries' => 0,
            'details'   => 0,
            'errors'    => 0,
            'returns'   => 0,
            'atoz'      => 0,
            'safet'     => 0,
        ];

        // Returns report window is max 60 days.
        $days = max(1, min(60, $lookbackDays));
        $rows = $client->fetchReturnsAndClaims($days, 120);

        foreach ($rows as $mapped) {
            if (!is_array($mapped)) {
                continue;
            }
            $kind = strtolower(trim((string) ($mapped['kind'] ?? '')));
            $ext = trim((string) ($mapped['external_id'] ?? ''));
            if ($ext === '' || !in_array($kind, ['return', 'atoz', 'safet'], true)) {
                continue;
            }
            try {
                $action = $this->cases->upsert((int) $channel->id, $kind, $ext, $mapped);
                $stats[$action]++;
                if ($kind === 'return') {
                    $stats['returns']++;
                    $stats['cases']++;
                } elseif ($kind === 'atoz') {
                    $stats['atoz']++;
                    $stats['inquiries']++;
                } else {
                    $stats['safet']++;
                    $stats['inquiries']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log("Amazon {$kind} upsert [{$ext}]: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * B&Q Mirakl incidents + dispute-like inbox threads → kind bnq_incident.
     *
     * @return array{created: int, updated: int, cases: int, inquiries: int, details: int, errors: int, incidents: int}
     */
    private function syncBnq(Channel $channel): array
    {
        $client = $this->factory->make($channel);
        if (!$client instanceof BnqChannelClient) {
            throw new RuntimeException('B&Q client unavailable.');
        }

        $stats = [
            'created'    => 0,
            'updated'    => 0,
            'cases'      => 0,
            'inquiries'  => 0,
            'details'    => 0,
            'errors'     => 0,
            'incidents'  => 0,
        ];

        try {
            $rows = $client->fetchIncidentsAndDisputes(80);
        } catch (\Throwable $e) {
            $stats['errors']++;
            error_log('B&Q incident sync: ' . $e->getMessage());
            return $stats;
        }

        foreach ($rows as $mapped) {
            if (!is_array($mapped)) {
                continue;
            }
            $ext = trim((string) ($mapped['external_id'] ?? ''));
            if ($ext === '') {
                continue;
            }
            try {
                $action = $this->cases->upsert((int) $channel->id, 'bnq_incident', $ext, $mapped);
                $stats[$action]++;
                $stats['cases']++;
                $stats['incidents']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log("B&Q incident upsert [{$ext}]: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Sync every enabled + connected eBay / Amazon / B&Q channel.
     *
     * @return array{channels: int, created: int, updated: int, cases: int, inquiries: int, details: int, errors: int}
     */
    public function syncAllEnabled(int $lookbackDays = 90): array
    {
        if (!TenantContext::get()->has()) {
            return [
                'channels' => 0, 'created' => 0, 'updated' => 0, 'cases' => 0,
                'inquiries' => 0, 'details' => 0, 'errors' => 0,
            ];
        }

        $channels = (new ChannelRepository($this->db))->enabled();
        $totals = [
            'channels'  => 0,
            'created'   => 0,
            'updated'   => 0,
            'cases'     => 0,
            'inquiries' => 0,
            'details'   => 0,
            'errors'    => 0,
        ];

        foreach ($channels as $channel) {
            if (!in_array($channel->type, ['ebay', 'amazon', 'bnq'], true) || !$channel->isConnected()) {
                continue;
            }
            $totals['channels']++;
            try {
                $stats = $this->syncChannel($channel, $lookbackDays);
                foreach (['created', 'updated', 'cases', 'inquiries', 'details', 'errors'] as $key) {
                    $totals[$key] += (int) ($stats[$key] ?? 0);
                }
            } catch (\Throwable $e) {
                $totals['errors']++;
                error_log('Case syncAll [' . $channel->name . ']: ' . $e->getMessage());
            }
        }

        return $totals;
    }

    /**
     * Refresh one case/inquiry detail from the channel.
     *
     * @return array<string, mixed>
     */
    public function refreshDetail(Channel $channel, string $kind, string $externalId): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            throw new RuntimeException('Channel does not belong to the current tenant.');
        }

        if ($channel->type === 'amazon') {
            return $this->refreshAmazonDetail($channel, $kind, $externalId);
        }

        $client = $this->factory->make($channel);
        if (!$client instanceof EbayChannelClient) {
            throw new RuntimeException('eBay client unavailable.');
        }

        $kind = strtolower($kind) === 'inquiry' ? 'inquiry' : 'case';
        if ($kind === 'inquiry') {
            $detail = $client->getSupportInquiry($externalId);
        } else {
            $detail = $client->getSupportCase($externalId);
        }

        $mapped = $kind === 'inquiry'
            ? $this->mapInquiry($detail)
            : $this->mapCase($detail);

        $mapped['detail'] = $detail;
        $this->cases->upsert((int) $channel->id, $kind, $externalId, $mapped);

        return $detail;
    }

    /**
     * Re-pull Amazon returns/claims window and refresh the matching row.
     *
     * @return array<string, mixed>
     */
    private function refreshAmazonDetail(Channel $channel, string $kind, string $externalId): array
    {
        $client = $this->factory->make($channel);
        if (!$client instanceof AmazonChannelClient) {
            throw new RuntimeException('Amazon client unavailable.');
        }

        $kind = strtolower(trim($kind));
        $externalId = trim($externalId);
        $rows = $client->fetchReturnsAndClaims(60, 120);
        foreach ($rows as $mapped) {
            if (!is_array($mapped)) {
                continue;
            }
            if (strtolower((string) ($mapped['kind'] ?? '')) !== $kind) {
                continue;
            }
            if (trim((string) ($mapped['external_id'] ?? '')) !== $externalId) {
                continue;
            }
            $this->cases->upsert((int) $channel->id, $kind, $externalId, $mapped);
            return is_array($mapped['detail'] ?? null) ? $mapped['detail'] : $mapped;
        }

        throw new RuntimeException(
            'Amazon ' . $kind . ' ' . $externalId . ' was not in the latest returns/claims pull.'
        );
    }

    /** @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:int} $stats */
    private function pullCases(
        EbayChannelClient $client,
        int $channelId,
        string $status,
        int $lookbackDays,
        array &$stats,
    ): void {
        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 10; $page++) {
            $result = $client->searchSupportCases($status, $limit, $offset, $lookbackDays);
            $members = $result['members'] ?? [];
            if ($members === []) {
                break;
            }
            foreach ($members as $row) {
                $this->ingestMember($client, $channelId, 'case', $row, $stats);
            }
            if (count($members) < $limit) {
                break;
            }
            $offset += $limit;
        }
    }

    /** @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:int} $stats */
    private function pullInquiries(
        EbayChannelClient $client,
        int $channelId,
        string $status,
        int $lookbackDays,
        array &$stats,
    ): void {
        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 10; $page++) {
            $result = $client->searchSupportInquiries($status, $limit, $offset, $lookbackDays);
            $members = $result['members'] ?? [];
            if ($members === []) {
                break;
            }
            foreach ($members as $row) {
                $this->ingestMember($client, $channelId, 'inquiry', $row, $stats);
            }
            if (count($members) < $limit) {
                break;
            }
            $offset += $limit;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:int} $stats
     */
    private function ingestMember(
        EbayChannelClient $client,
        int $channelId,
        string $kind,
        array $row,
        array &$stats,
    ): void {
        $mapped = $kind === 'inquiry' ? $this->mapInquiry($row) : $this->mapCase($row);
        $ext = (string) ($mapped['external_id'] ?? '');
        if ($ext === '') {
            return;
        }

        $status = strtoupper((string) ($mapped['status'] ?? ''));
        $wantDetail = !in_array($status, self::CLOSED_STATUSES, true);

        if ($wantDetail) {
            try {
                $detail = $kind === 'inquiry'
                    ? $client->getSupportInquiry($ext)
                    : $client->getSupportCase($ext);
                $rich = $kind === 'inquiry' ? $this->mapInquiry($detail) : $this->mapCase($detail);
                // Keep search summary; prefer detail-mapped fields.
                $rich['summary'] = $row;
                $rich['detail'] = $detail;
                $mapped = $rich;
                $stats['details']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log("eBay {$kind} detail [{$ext}]: " . $e->getMessage());
            }
        }

        $action = $this->cases->upsert($channelId, $kind, $ext, $mapped);
        $stats[$action]++;
        $stats[$kind === 'inquiry' ? 'inquiries' : 'cases']++;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapCase(array $row): array
    {
        $caseId = (string) ($row['caseId'] ?? $row['case_id'] ?? $row['id'] ?? '');
        $details = is_array($row['caseDetails'] ?? null) ? $row['caseDetails'] : $row;
        $item = is_array($details['itemDetails'] ?? null) ? $details['itemDetails'] : [];
        $claim = is_array($details['claimAmount'] ?? null) ? $details['claimAmount'] : [];
        $status = (string) (
            $row['caseStatusEnum']
            ?? $details['caseStatusEnum']
            ?? $row['status']
            ?? $details['status']
            ?? ''
        );

        return [
            'external_id'         => $caseId,
            'case_type'           => (string) ($row['caseType'] ?? $details['caseType'] ?? 'CASE'),
            'status'              => $status,
            'item_id'             => (string) ($item['itemId'] ?? $details['itemId'] ?? $row['itemId'] ?? ''),
            'transaction_id'      => (string) ($item['transactionId'] ?? $details['transactionId'] ?? $row['transactionId'] ?? ''),
            'order_id'            => (string) ($details['legacyOrderId'] ?? $details['orderId'] ?? ''),
            'buyer_login'         => $this->loginName($details['buyer'] ?? $row['buyer'] ?? ''),
            'claim_amount'        => isset($claim['value']) ? (float) $claim['value'] : null,
            'claim_currency'      => (string) ($claim['currency'] ?? ''),
            'item_title'          => (string) ($item['itemTitle'] ?? $details['itemTitle'] ?? ''),
            'seller_response_due' => $this->parseDate(
                $details['respondByDate']
                ?? $row['respondByDate']
                ?? $details['sellerResponseDueDate']
                ?? $row['sellerResponseDueDate']
                ?? null
            ),
            'creation_date'       => $this->parseDate(
                $details['creationDate'] ?? $row['creationDate'] ?? null
            ),
            'last_modified_date'  => $this->parseDate(
                $details['lastModifiedDate'] ?? $row['lastModifiedDate'] ?? null
            ),
            'summary'             => $row,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapInquiry(array $row): array
    {
        $id = (string) ($row['inquiryId'] ?? $row['inquiry_id'] ?? $row['id'] ?? '');
        $details = is_array($row['inquiryDetails'] ?? null) ? $row['inquiryDetails'] : $row;
        $item = is_array($details['itemDetails'] ?? null) ? $details['itemDetails'] : [];
        $claim = is_array($details['claimAmount'] ?? null) ? $details['claimAmount'] : [];

        return [
            'external_id'         => $id,
            'case_type'           => (string) ($row['inquiryType'] ?? $details['inquiryType'] ?? 'ITEM_NOT_RECEIVED'),
            'status'              => (string) (
                $row['inquiryStatusEnum']
                ?? $details['inquiryStatusEnum']
                ?? $row['status']
                ?? $details['status']
                ?? ''
            ),
            'item_id'             => (string) ($item['itemId'] ?? $details['itemId'] ?? $row['itemId'] ?? ''),
            'transaction_id'      => (string) ($item['transactionId'] ?? $details['transactionId'] ?? $row['transactionId'] ?? ''),
            'order_id'            => (string) ($details['legacyOrderId'] ?? $details['orderId'] ?? ''),
            'buyer_login'         => $this->loginName($details['buyer'] ?? $row['buyer'] ?? ''),
            'claim_amount'        => isset($claim['value']) ? (float) $claim['value'] : null,
            'claim_currency'      => (string) ($claim['currency'] ?? ''),
            'item_title'          => (string) ($item['itemTitle'] ?? $details['itemTitle'] ?? ''),
            'seller_response_due' => $this->parseDate(
                $details['respondByDate']
                ?? $row['respondByDate']
                ?? $details['sellerResponseDueDate']
                ?? $row['sellerResponseDueDate']
                ?? null
            ),
            'creation_date'       => $this->parseDate(
                $details['creationDate'] ?? $row['creationDate'] ?? null
            ),
            'last_modified_date'  => $this->parseDate(
                $details['lastModifiedDate'] ?? $row['lastModifiedDate'] ?? null
            ),
            'summary'             => $row,
        ];
    }

    private function loginName(mixed $buyer): string
    {
        if (is_string($buyer) || is_numeric($buyer)) {
            return trim((string) $buyer);
        }
        if (is_array($buyer)) {
            return trim((string) ($buyer['loginName'] ?? $buyer['username'] ?? $buyer['name'] ?? ''));
        }
        return '';
    }

    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $raw = $raw['value'] ?? $raw['formattedValue'] ?? null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function channelTenantAllowed(Channel $channel): bool
    {
        $tid = (int) $channel->tenantId;
        if ($tid <= 0) {
            return false;
        }
        if (TenantContext::get()->has() && TenantContext::get()->id() !== $tid) {
            return false;
        }
        return true;
    }
}
