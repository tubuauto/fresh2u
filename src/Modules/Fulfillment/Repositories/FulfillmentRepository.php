<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Repositories;

use PDO;

final class FulfillmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createMerchantFulfillment(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO merchant_fulfillment_orders (
                id, tenant_merchant_id, leader_order_id, merchant_id, supply_partner_id, pickup_hub_id,
                status, routed_at, created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :leader_order_id, :merchant_id, :supply_partner_id, :pickup_hub_id,
                :status, NOW(), NOW(), NOW()
            )
            RETURNING *'
        );

        $stmt->execute([
            'tenant_merchant_id' => $data['tenant_merchant_id'],
            'leader_order_id' => $data['leader_order_id'],
            'merchant_id' => $data['merchant_id'],
            'supply_partner_id' => $data['supply_partner_id'],
            'pickup_hub_id' => $data['pickup_hub_id'],
            'status' => 'routed',
        ]);

        return $stmt->fetch() ?: [];
    }

    public function findFulfillment(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM merchant_fulfillment_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateFulfillmentStatus(string $id, string $status, bool $markFulfilled = false): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE merchant_fulfillment_orders
            SET status = :status,
                fulfilled_at = CASE WHEN :mark_fulfilled THEN NOW() ELSE fulfilled_at END,
                updated_at = NOW()
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'mark_fulfilled' => $markFulfilled,
        ]);
    }

    public function updateLeaderOrderStatus(string $leaderOrderId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE leader_orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $leaderOrderId, 'status' => $status]);
    }

    public function updateMemberOrdersByLeaderOrder(string $leaderOrderId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_orders SET status = :status, updated_at = NOW() WHERE leader_order_id = :leader_order_id');
        $stmt->execute([
            'leader_order_id' => $leaderOrderId,
            'status' => $status,
        ]);
    }

    public function addReceipt(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pickup_hub_receipts (
                id, tenant_merchant_id, fulfillment_order_id, leader_order_id, pickup_hub_id,
                received_by_user_id, received_at, note, created_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :fulfillment_order_id, :leader_order_id, :pickup_hub_id,
                :received_by_user_id, NOW(), :note, NOW()
            )
            RETURNING *'
        );

        $stmt->execute([
            'tenant_merchant_id' => $data['tenant_merchant_id'],
            'fulfillment_order_id' => $data['fulfillment_order_id'],
            'leader_order_id' => $data['leader_order_id'],
            'pickup_hub_id' => $data['pickup_hub_id'],
            'received_by_user_id' => $data['received_by_user_id'],
            'note' => $data['note'],
        ]);

        return $stmt->fetch() ?: [];
    }

    public function addHandover(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pickup_hub_handovers (
                id, tenant_merchant_id, receipt_id, pickup_hub_id, handover_to_type,
                handover_to_id, handover_at, note, created_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :receipt_id, :pickup_hub_id, :handover_to_type,
                :handover_to_id, NOW(), :note, NOW()
            )
            RETURNING *'
        );

        $stmt->execute([
            'tenant_merchant_id' => $data['tenant_merchant_id'],
            'receipt_id' => $data['receipt_id'],
            'pickup_hub_id' => $data['pickup_hub_id'],
            'handover_to_type' => $data['handover_to_type'],
            'handover_to_id' => $data['handover_to_id'],
            'note' => $data['note'],
        ]);

        return $stmt->fetch() ?: [];
    }

    public function createDeliveryTask(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO delivery_tasks (
                id, tenant_merchant_id, member_order_id, leader_order_id, pickup_hub_id, driver_user_id,
                driver_type, assigned_by_user_id, status, created_at, updated_at
            ) VALUES (
                gen_random_uuid(), :tenant_merchant_id, :member_order_id, :leader_order_id, :pickup_hub_id, :driver_user_id,
                :driver_type, :assigned_by_user_id, :status, NOW(), NOW()
            )
            RETURNING *'
        );

        $stmt->execute([
            'tenant_merchant_id' => $data['tenant_merchant_id'],
            'member_order_id' => $data['member_order_id'],
            'leader_order_id' => $data['leader_order_id'],
            'pickup_hub_id' => $data['pickup_hub_id'],
            'driver_user_id' => $data['driver_user_id'],
            'driver_type' => $data['driver_type'],
            'assigned_by_user_id' => $data['assigned_by_user_id'],
            'status' => 'assigned',
        ]);

        return $stmt->fetch() ?: [];
    }

    public function findDeliveryTask(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM delivery_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateDeliveryStatus(string $id, string $status, ?string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE delivery_tasks
            SET status = :status,
                pickup_at = CASE WHEN :status = 'picked_up' THEN NOW() ELSE pickup_at END,
                delivered_at = CASE WHEN :status = 'delivered' THEN NOW() ELSE delivered_at END,
                fail_reason = :fail_reason,
                updated_at = NOW()
            WHERE id = :id"
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'fail_reason' => $reason,
        ]);
    }

    public function updateMemberOrderStatus(string $memberOrderId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE member_orders SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $memberOrderId, 'status' => $status]);
    }
    public function listDeliveryTasks(string $tenantMerchantId, array $filters, int $limit): array
    {
        $sql = 'SELECT * FROM delivery_tasks WHERE tenant_merchant_id = :tenant_merchant_id';
        $params = ['tenant_merchant_id' => $tenantMerchantId];

        if (!empty($filters['member_order_id'])) {
            $sql .= ' AND member_order_id = :member_order_id';
            $params['member_order_id'] = (string) $filters['member_order_id'];
        }

        if (!empty($filters['leader_order_id'])) {
            $sql .= ' AND leader_order_id = :leader_order_id';
            $params['leader_order_id'] = (string) $filters['leader_order_id'];
        }

        if (!empty($filters['pickup_hub_id'])) {
            $sql .= ' AND pickup_hub_id = :pickup_hub_id';
            $params['pickup_hub_id'] = (string) $filters['pickup_hub_id'];
        }

        if (!empty($filters['driver_user_id'])) {
            $sql .= ' AND driver_user_id = :driver_user_id';
            $params['driver_user_id'] = (string) $filters['driver_user_id'];
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

