<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use PDO;

final class SettlementRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchPendingSources(string $tenantMerchantId, string $ownerType, string $ownerId): array
    {
        $map = [
            'leader' => ['table' => 'commissions', 'owner_col' => 'leader_user_id'],
            'pickup_hub' => ['table' => 'pickup_hub_earnings', 'owner_col' => 'pickup_hub_id'],
            'driver' => ['table' => 'delivery_fee_earnings', 'owner_col' => 'driver_user_id'],
            'merchant' => ['table' => 'merchant_settlements', 'owner_col' => 'merchant_id'],
            'supply_partner' => ['table' => 'supply_partner_receivables', 'owner_col' => 'supply_partner_id'],
        ];

        $target = $map[$ownerType] ?? null;
        if ($target === null) {
            return [];
        }

        $sql = sprintf(
            'SELECT id, amount, :owner_type AS owner_type, %s AS owner_id, :source_table AS source_table
            FROM %s
            WHERE tenant_merchant_id = :tenant_merchant_id
              AND %s = :owner_id
              AND state = :state',
            $target['owner_col'],
            $target['table'],
            $target['owner_col']
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'owner_type' => $ownerType,
            'source_table' => $target['table'],
            'tenant_merchant_id' => $tenantMerchantId,
            'owner_id' => $ownerId,
            'state' => 'pending',
        ]);

        return $stmt->fetchAll();
    }

    public function createSettlement(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settlements (
                id, settlement_no, tenant_merchant_id, owner_type, owner_id,
                period_start, period_end, gross_amount, fee_amount, net_amount,
                state, created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :settlement_no, :tenant_merchant_id, :owner_type, :owner_id,
                :period_start, :period_end, :gross_amount, :fee_amount, :net_amount,
                :state, NOW(), NOW()
            ) RETURNING *'
        );

        $stmt->execute($data);
        return $stmt->fetch() ?: [];
    }

    public function addSettlementItem(array $item): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settlement_items (
                id, settlement_id, tenant_merchant_id, source_table, source_id,
                owner_type, owner_id, amount, currency, created_at
            ) VALUES (
                gen_random_uuid(), :settlement_id, :tenant_merchant_id, :source_table, :source_id,
                :owner_type, :owner_id, :amount, :currency, NOW()
            ) RETURNING *'
        );

        $stmt->execute($item);
        return $stmt->fetch() ?: [];
    }

    public function findSettlement(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM settlements WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function settlementItems(string $settlementId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM settlement_items WHERE settlement_id = :settlement_id ORDER BY created_at ASC');
        $stmt->execute(['settlement_id' => $settlementId]);
        return $stmt->fetchAll();
    }

    public function updateState(string $id, string $state, ?string $reviewedBy = null, bool $markPosted = false, bool $markPaid = false): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE settlements
            SET state = :state,
                reviewed_by = COALESCE(:reviewed_by, reviewed_by),
                reviewed_at = CASE WHEN :reviewed_by IS NOT NULL THEN NOW() ELSE reviewed_at END,
                posted_at = CASE WHEN :mark_posted THEN NOW() ELSE posted_at END,
                paid_at = CASE WHEN :mark_paid THEN NOW() ELSE paid_at END,
                updated_at = NOW()
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'state' => $state,
            'reviewed_by' => $reviewedBy,
            'mark_posted' => $markPosted,
            'mark_paid' => $markPaid,
        ]);
    }

    public function createLedgerEntry(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ledger_entries (
                id, tenant_merchant_id, settlement_id, source_type, source_id,
                owner_type, owner_id, entry_type, debit_amount, credit_amount,
                currency, occurred_at, remark, created_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :settlement_id, :source_type, :source_id,
                :owner_type, :owner_id, :entry_type, :debit_amount, :credit_amount,
                :currency, NOW(), :remark, NOW()
            ) RETURNING *'
        );

        $stmt->execute($data);
        return $stmt->fetch() ?: [];
    }

    public function markSourceSettled(string $table, string $id): void
    {
        $allowed = ['commissions', 'pickup_hub_earnings', 'delivery_fee_earnings', 'merchant_settlements', 'supply_partner_receivables'];
        if (!in_array($table, $allowed, true)) {
            return;
        }

        $sql = sprintf('UPDATE %s SET state = :state, settled_at = NOW() WHERE id = :id', $table);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['state' => 'settled', 'id' => $id]);
    }

    public function listLedgerEntries(string $tenantMerchantId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ledger_entries
            WHERE tenant_merchant_id = :tenant_merchant_id
            ORDER BY occurred_at DESC
            LIMIT :limit'
        );
        $stmt->bindValue(':tenant_merchant_id', $tenantMerchantId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
    public function listSettlements(string $tenantMerchantId, array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM settlements WHERE tenant_merchant_id = :tenant_merchant_id';
        $params = ['tenant_merchant_id' => $tenantMerchantId];

        if (!empty($filters['owner_type'])) {
            $sql .= ' AND owner_type = :owner_type';
            $params['owner_type'] = (string) $filters['owner_type'];
        }

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND owner_id = :owner_id';
            $params['owner_id'] = (string) $filters['owner_id'];
        }

        if (!empty($filters['state'])) {
            $sql .= ' AND state = :state';
            $params['state'] = (string) $filters['state'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

