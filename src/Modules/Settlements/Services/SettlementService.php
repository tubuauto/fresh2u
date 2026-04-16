<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Modules\Settlements\Repositories\SettlementRepository;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Support\Exceptions\HttpException;
use PDO;

final class SettlementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettlementRepository $repository,
        private readonly WalletService $walletService,
    ) {
    }

    public function createSettlement(array $payload, string $tenantMerchantId): array
    {
        $ownerType = (string) ($payload['owner_type'] ?? '');
        $ownerId = (string) ($payload['owner_id'] ?? '');
        if ($ownerType === '' || $ownerId === '') {
            throw new HttpException(422, 'owner_type and owner_id are required');
        }

        $sources = $this->repository->fetchPendingSources($tenantMerchantId, $ownerType, $ownerId);
        if (count($sources) === 0) {
            throw new HttpException(422, 'No pending earnings found for settlement');
        }

        $gross = 0.0;
        foreach ($sources as $source) {
            $gross += (float) $source['amount'];
        }
        $fee = round((float) ($payload['fee_amount'] ?? 0), 2);
        $net = max(0.0, round($gross - $fee, 2));

        $this->pdo->beginTransaction();
        try {
            $settlement = $this->repository->createSettlement([
                'settlement_no' => 'SET-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'tenant_merchant_id' => $tenantMerchantId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'period_start' => $payload['period_start'] ?? null,
                'period_end' => $payload['period_end'] ?? null,
                'gross_amount' => number_format(round($gross, 2), 2, '.', ''),
                'fee_amount' => number_format($fee, 2, '.', ''),
                'net_amount' => number_format($net, 2, '.', ''),
                'state' => 'ready_for_review',
            ]);

            foreach ($sources as $source) {
                $this->repository->addSettlementItem([
                    'settlement_id' => $settlement['id'],
                    'tenant_merchant_id' => $tenantMerchantId,
                    'source_table' => $source['source_table'],
                    'source_id' => $source['id'],
                    'owner_type' => $source['owner_type'],
                    'owner_id' => $source['owner_id'],
                    'amount' => number_format((float) $source['amount'], 2, '.', ''),
                    'currency' => $payload['currency'] ?? 'CNY',
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getSettlement((string) $settlement['id']);
    }

    public function reviewSettlement(string $settlementId, string $action, string $reviewerId): array
    {
        $settlement = $this->getSettlement($settlementId);
        if (!in_array((string) $settlement['state'], ['ready_for_review', 'approved', 'rejected'], true)) {
            throw new HttpException(422, 'Settlement is not reviewable');
        }

        if ($action === 'reject') {
            $this->repository->updateState($settlementId, 'rejected', $reviewerId, false, false);
        } else {
            $this->repository->updateState($settlementId, 'approved', $reviewerId, false, false);
        }

        return $this->getSettlement($settlementId);
    }

    public function postSettlement(string $settlementId): array
    {
        $settlement = $this->getSettlement($settlementId);
        if ((string) $settlement['state'] !== 'approved') {
            throw new HttpException(422, 'Settlement must be approved before posting');
        }

        $items = $this->repository->settlementItems($settlementId);
        if (count($items) === 0) {
            throw new HttpException(422, 'Settlement has no items');
        }

        $entryTypeMap = [
            'commissions' => 'commission_expense',
            'pickup_hub_earnings' => 'pickup_hub_fee_expense',
            'delivery_fee_earnings' => 'delivery_fee_expense',
            'merchant_settlements' => 'merchant_settlement_payable',
            'supply_partner_receivables' => 'supply_partner_settlement_payable',
        ];

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $sourceTable = (string) $item['source_table'];
                $entryType = $entryTypeMap[$sourceTable] ?? 'platform_adjustment';

                $this->repository->createLedgerEntry([
                    'tenant_merchant_id' => $settlement['tenant_merchant_id'],
                    'settlement_id' => $settlementId,
                    'source_type' => $sourceTable,
                    'source_id' => $item['source_id'],
                    'owner_type' => $item['owner_type'],
                    'owner_id' => $item['owner_id'],
                    'entry_type' => $entryType,
                    'debit_amount' => '0.00',
                    'credit_amount' => number_format((float) $item['amount'], 2, '.', ''),
                    'currency' => $item['currency'],
                    'remark' => 'Settlement posted',
                ]);

                $this->walletService->credit(
                    (string) $settlement['tenant_merchant_id'],
                    (string) $item['owner_type'],
                    (string) $item['owner_id'],
                    'withdrawable_balance',
                    (float) $item['amount'],
                    'settlement_credit',
                    'settlement_item',
                    (string) $item['id'],
                    (string) $settlement['settlement_no'],
                    'Settlement posted to wallet'
                );

                $this->repository->markSourceSettled($sourceTable, (string) $item['source_id']);
            }

            $this->repository->updateState($settlementId, 'posted', null, true, true);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getSettlement($settlementId);
    }
    public function listSettlements(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'owner_type' => (string) ($query['owner_type'] ?? ''),
            'owner_id' => (string) ($query['owner_id'] ?? ''),
            'state' => (string) ($query['state'] ?? ''),
        ];

        $role = (string) ($actor['role'] ?? '');
        if (!in_array($role, ['admin', 'merchant'], true)) {
            if ($filters['owner_type'] === '') {
                $filters['owner_type'] = $this->ownerTypeFromRole($role);
            }

            if ($filters['owner_id'] === '') {
                $filters['owner_id'] = (string) $actor['id'];
            }
        }

        $limit = (int) ($query['limit'] ?? 50);
        return $this->repository->listSettlements($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }
    public function listLedgerEntries(string $tenantMerchantId, int $limit): array
    {
        return $this->repository->listLedgerEntries($tenantMerchantId, max(1, min($limit, 200)));
    }

    public function getSettlement(string $settlementId): array
    {
        $settlement = $this->repository->findSettlement($settlementId);
        if ($settlement === null) {
            throw new HttpException(404, 'Settlement not found');
        }
        $settlement['items'] = $this->repository->settlementItems($settlementId);
        return $settlement;
    }
    private function ownerTypeFromRole(string $role): string
    {
        return match ($role) {
            'leader' => 'leader',
            'pickup_hub' => 'pickup_hub',
            'driver' => 'driver',
            'supply_partner' => 'supply_partner',
            'merchant' => 'merchant',
            default => '',
        };
    }
}

