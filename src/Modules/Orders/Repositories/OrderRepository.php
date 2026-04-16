<?php

declare(strict_types=1);

namespace App\Modules\Orders\Repositories;

use PDO;

final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createMemberOrder(array $order, array $items): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_orders (
                id, order_no, tenant_merchant_id, leader_user_id, customer_user_id, placed_by_type, placed_by_user_id,
                status, payment_status, collection_status,
                subtotal_amount, coupon_discount_amount, payable_amount, wallet_deducted_amount, external_paid_amount,
                currency, created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :order_no, :tenant_merchant_id, :leader_user_id, :customer_user_id, :placed_by_type, :placed_by_user_id,
                :status, :payment_status, :collection_status,
                :subtotal_amount, :coupon_discount_amount, :payable_amount, :wallet_deducted_amount, :external_paid_amount,
                :currency, NOW(), NOW()
            )
            RETURNING *'
        );

        $stmt->execute($order);
        $created = $stmt->fetch() ?: [];

        foreach ($items as $item) {
            $insertItem = $this->pdo->prepare(
                'INSERT INTO member_order_items (
                    id, tenant_merchant_id, member_order_id, product_id, product_spec_id,
                    product_name, qty, unit_price, line_amount, created_at
                ) VALUES (
                    gen_random_uuid(), :tenant_merchant_id, :member_order_id, :product_id, :product_spec_id,
                    :product_name, :qty, :unit_price, :line_amount, NOW()
                )'
            );
            $insertItem->execute([
                'tenant_merchant_id' => $order['tenant_merchant_id'],
                'member_order_id' => $created['id'],
                'product_id' => $item['product_id'],
                'product_spec_id' => $item['product_spec_id'],
                'product_name' => $item['product_name'],
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'line_amount' => $item['line_amount'],
            ]);
        }

        return $created;
    }

    public function findMemberOrder(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function memberOrderItems(string $memberOrderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_order_items WHERE member_order_id = :member_order_id');
        $stmt->execute(['member_order_id' => $memberOrderId]);
        return $stmt->fetchAll();
    }

    public function updateMemberOrderCoupon(string $id, string $couponId, string $claimId, string $discountAmount, string $payableAmount): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_orders
            SET coupon_id = :coupon_id,
                coupon_claim_id = :coupon_claim_id,
                coupon_discount_amount = :coupon_discount_amount,
                payable_amount = :payable_amount,
                updated_at = NOW()
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'coupon_id' => $couponId,
            'coupon_claim_id' => $claimId,
            'coupon_discount_amount' => $discountAmount,
            'payable_amount' => $payableAmount,
        ]);
    }

    public function addPaymentAllocation(string $tenantMerchantId, string $memberOrderId, string $source, string $amount, string $currency): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_payment_allocations (
                id, tenant_merchant_id, member_order_id, payment_source, amount, currency, created_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :member_order_id, :payment_source, :amount, :currency, NOW()
            )'
        );
        $stmt->execute([
            'tenant_merchant_id' => $tenantMerchantId,
            'member_order_id' => $memberOrderId,
            'payment_source' => $source,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    public function updateMemberOrderPayment(string $id, array $payment): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_orders
            SET payment_status = :payment_status,
                status = :status,
                wallet_deducted_amount = :wallet_deducted_amount,
                external_paid_amount = :external_paid_amount,
                updated_at = NOW()
            WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'payment_status' => $payment['payment_status'],
            'status' => $payment['status'],
            'wallet_deducted_amount' => $payment['wallet_deducted_amount'],
            'external_paid_amount' => $payment['external_paid_amount'],
        ]);
    }

    public function updateMemberOrderCollection(string $id, array $collection): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_orders
            SET collection_status = :collection_status,
                collection_method = :collection_method,
                collected_amount = :collected_amount,
                collected_at = :collected_at,
                collection_note = :collection_note,
                updated_at = NOW()
            WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'collection_status' => $collection['collection_status'],
            'collection_method' => $collection['collection_method'],
            'collected_amount' => $collection['collected_amount'],
            'collected_at' => $collection['collected_at'],
            'collection_note' => $collection['collection_note'],
        ]);
    }

    public function getMemberOrdersForConsolidation(string $tenantMerchantId, array $memberOrderIds): array
    {
        $placeholders = implode(',', array_fill(0, count($memberOrderIds), '?'));
        $sql = "SELECT * FROM member_orders WHERE tenant_merchant_id = ? AND id IN ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([$tenantMerchantId], $memberOrderIds);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function createLeaderOrder(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO leader_orders (
                id, order_no, tenant_merchant_id, merchant_id, leader_user_id, pickup_hub_id,
                status, subtotal_amount, coupon_discount_amount, payable_amount, currency, note,
                created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :order_no, :tenant_merchant_id, :merchant_id, :leader_user_id, :pickup_hub_id,
                :status, :subtotal_amount, :coupon_discount_amount, :payable_amount, :currency, :note,
                NOW(), NOW()
            )
            RETURNING *'
        );

        $stmt->execute($data);
        return $stmt->fetch() ?: [];
    }

    public function addLeaderOrderItemsFromMemberOrders(string $tenantMerchantId, string $leaderOrderId, array $memberOrderIds): void
    {
        $placeholders = implode(',', array_fill(0, count($memberOrderIds), '?'));
        $sql = "INSERT INTO leader_order_items (
            id, tenant_merchant_id, leader_order_id, member_order_id, product_id, product_spec_id,
            product_name, qty, unit_price, line_amount, created_at
        )
        SELECT
            gen_random_uuid(), moi.tenant_merchant_id, ?, moi.member_order_id, moi.product_id, moi.product_spec_id,
            moi.product_name, moi.qty, moi.unit_price, moi.line_amount, NOW()
        FROM member_order_items moi
        WHERE moi.tenant_merchant_id = ? AND moi.member_order_id IN ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([$leaderOrderId, $tenantMerchantId], $memberOrderIds);
        $stmt->execute($params);
    }

    public function attachMemberOrdersToLeaderOrder(string $leaderOrderId, array $memberOrderIds): void
    {
        $placeholders = implode(',', array_fill(0, count($memberOrderIds), '?'));
        $sql = "UPDATE member_orders
            SET leader_order_id = ?,
                status = 'assigned_to_leader_order',
                updated_at = NOW()
            WHERE id IN ({$placeholders})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$leaderOrderId], $memberOrderIds));
    }

    public function findLeaderOrder(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM leader_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateLeaderOrderStatus(string $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE leader_orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id, 'status' => $status]);
    }
    public function listMemberOrders(string $tenantMerchantId, array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM member_orders WHERE tenant_merchant_id = :tenant_merchant_id';
        $params = ['tenant_merchant_id' => $tenantMerchantId];

        if (!empty($filters['leader_user_id'])) {
            $sql .= ' AND leader_user_id = :leader_user_id';
            $params['leader_user_id'] = (string) $filters['leader_user_id'];
        }

        if (!empty($filters['customer_user_id'])) {
            $sql .= ' AND customer_user_id = :customer_user_id';
            $params['customer_user_id'] = (string) $filters['customer_user_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['payment_status'])) {
            $sql .= ' AND payment_status = :payment_status';
            $params['payment_status'] = (string) $filters['payment_status'];
        }

        if (!empty($filters['collection_status'])) {
            $sql .= ' AND collection_status = :collection_status';
            $params['collection_status'] = (string) $filters['collection_status'];
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

    public function listLeaderOrders(string $tenantMerchantId, array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM leader_orders WHERE tenant_merchant_id = :tenant_merchant_id';
        $params = ['tenant_merchant_id' => $tenantMerchantId];

        if (!empty($filters['merchant_id'])) {
            $sql .= ' AND merchant_id = :merchant_id';
            $params['merchant_id'] = (string) $filters['merchant_id'];
        }

        if (!empty($filters['leader_user_id'])) {
            $sql .= ' AND leader_user_id = :leader_user_id';
            $params['leader_user_id'] = (string) $filters['leader_user_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
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

