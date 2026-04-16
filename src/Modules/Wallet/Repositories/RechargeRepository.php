<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Repositories;

use PDO;

final class RechargeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO recharge_orders (
                id, order_no, tenant_merchant_id, customer_user_id, wallet_id, currency,
                recharge_amount, bonus_amount, payable_amount, payment_method, payment_status,
                status, initiated_by_type, initiated_by_id, received_by_type, received_by_id,
                received_at, proof_note, proof_image, reviewed_by, reviewed_at, paid_at, expired_at,
                created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :order_no, :tenant_merchant_id, :customer_user_id, :wallet_id, :currency,
                :recharge_amount, :bonus_amount, :payable_amount, :payment_method, :payment_status,
                :status, :initiated_by_type, :initiated_by_id, :received_by_type, :received_by_id,
                :received_at, :proof_note, :proof_image, :reviewed_by, :reviewed_at, :paid_at, :expired_at,
                NOW(), NOW()
            )
            RETURNING *'
        );

        $stmt->execute([
            'order_no' => $data['order_no'],
            'tenant_merchant_id' => $data['tenant_merchant_id'],
            'customer_user_id' => $data['customer_user_id'],
            'wallet_id' => $data['wallet_id'],
            'currency' => $data['currency'],
            'recharge_amount' => $data['recharge_amount'],
            'bonus_amount' => $data['bonus_amount'],
            'payable_amount' => $data['payable_amount'],
            'payment_method' => $data['payment_method'],
            'payment_status' => $data['payment_status'],
            'status' => $data['status'],
            'initiated_by_type' => $data['initiated_by_type'],
            'initiated_by_id' => $data['initiated_by_id'],
            'received_by_type' => $data['received_by_type'],
            'received_by_id' => $data['received_by_id'],
            'received_at' => $data['received_at'],
            'proof_note' => $data['proof_note'],
            'proof_image' => $data['proof_image'],
            'reviewed_by' => $data['reviewed_by'],
            'reviewed_at' => $data['reviewed_at'],
            'paid_at' => $data['paid_at'],
            'expired_at' => $data['expired_at'],
        ]);

        return $stmt->fetch() ?: [];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recharge_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateStatus(string $id, string $status, string $paymentStatus, ?string $reviewedBy = null, bool $markPaid = false): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE recharge_orders
            SET status = :status,
                payment_status = :payment_status,
                reviewed_by = COALESCE(:reviewed_by, reviewed_by),
                reviewed_at = CASE WHEN :reviewed_by IS NOT NULL THEN NOW() ELSE reviewed_at END,
                paid_at = CASE WHEN :mark_paid THEN NOW() ELSE paid_at END,
                updated_at = NOW()
            WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'reviewed_by' => $reviewedBy,
            'mark_paid' => $markPaid,
        ]);
    }
    public function listByTenant(string $tenantMerchantId, array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM recharge_orders WHERE tenant_merchant_id = :tenant_merchant_id';
        $params = ['tenant_merchant_id' => $tenantMerchantId];

        if (!empty($filters['customer_user_id'])) {
            $sql .= ' AND customer_user_id = :customer_user_id';
            $params['customer_user_id'] = (string) $filters['customer_user_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['payment_method'])) {
            $sql .= ' AND payment_method = :payment_method';
            $params['payment_method'] = (string) $filters['payment_method'];
        }

        if (!empty($filters['initiated_by_id'])) {
            $sql .= ' AND initiated_by_id = :initiated_by_id';
            $params['initiated_by_id'] = (string) $filters['initiated_by_id'];
        }

        if (!empty($filters['received_by_id'])) {
            $sql .= ' AND received_by_id = :received_by_id';
            $params['received_by_id'] = (string) $filters['received_by_id'];
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

