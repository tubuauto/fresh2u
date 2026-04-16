<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Services;

use App\Modules\Fulfillment\Repositories\FulfillmentRepository;
use App\Shared\Support\Exceptions\HttpException;
use PDO;

final class FulfillmentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FulfillmentRepository $repository,
    ) {
    }

    public function routeFulfillment(array $payload, array $actor, string $tenantMerchantId, string $leaderOrderId): array
    {
        $pickupHubId = (string) ($payload['pickup_hub_id'] ?? '');
        if ($pickupHubId === '') {
            throw new HttpException(422, 'pickup_hub_id is required');
        }

        $fulfillment = $this->repository->createMerchantFulfillment([
            'tenant_merchant_id' => $tenantMerchantId,
            'leader_order_id' => $leaderOrderId,
            'merchant_id' => $payload['merchant_id'] ?? $tenantMerchantId,
            'supply_partner_id' => $payload['supply_partner_id'] ?? null,
            'pickup_hub_id' => $pickupHubId,
        ]);

        $this->repository->updateLeaderOrderStatus($leaderOrderId, 'routed_to_supply_partner');
        return $fulfillment;
    }

    public function markMerchantFulfilled(string $fulfillmentId): array
    {
        $fulfillment = $this->repository->findFulfillment($fulfillmentId);
        if ($fulfillment === null) {
            throw new HttpException(404, 'Fulfillment order not found');
        }

        $this->repository->updateFulfillmentStatus($fulfillmentId, 'merchant_fulfilled', true);
        $this->repository->updateLeaderOrderStatus((string) $fulfillment['leader_order_id'], 'merchant_fulfilled');
        $this->repository->updateMemberOrdersByLeaderOrder((string) $fulfillment['leader_order_id'], 'merchant_fulfilled');

        return $this->repository->findFulfillment($fulfillmentId) ?? $fulfillment;
    }

    public function recordReceipt(string $pickupHubId, array $payload, array $actor, string $tenantMerchantId): array
    {
        $fulfillmentId = (string) ($payload['fulfillment_order_id'] ?? '');
        $leaderOrderId = (string) ($payload['leader_order_id'] ?? '');
        if ($fulfillmentId === '' || $leaderOrderId === '') {
            throw new HttpException(422, 'fulfillment_order_id and leader_order_id are required');
        }

        $receipt = $this->repository->addReceipt([
            'tenant_merchant_id' => $tenantMerchantId,
            'fulfillment_order_id' => $fulfillmentId,
            'leader_order_id' => $leaderOrderId,
            'pickup_hub_id' => $pickupHubId,
            'received_by_user_id' => $actor['id'],
            'note' => $payload['note'] ?? null,
        ]);

        $this->repository->updateLeaderOrderStatus($leaderOrderId, 'at_pickup_hub');
        $this->repository->updateMemberOrdersByLeaderOrder($leaderOrderId, 'at_pickup_hub');

        return $receipt;
    }

    public function recordHandover(string $pickupHubId, array $payload, string $tenantMerchantId): array
    {
        $receiptId = (string) ($payload['receipt_id'] ?? '');
        $handoverToType = (string) ($payload['handover_to_type'] ?? 'driver');
        $handoverToId = (string) ($payload['handover_to_id'] ?? '');
        if ($receiptId === '' || $handoverToId === '') {
            throw new HttpException(422, 'receipt_id and handover_to_id are required');
        }

        return $this->repository->addHandover([
            'tenant_merchant_id' => $tenantMerchantId,
            'receipt_id' => $receiptId,
            'pickup_hub_id' => $pickupHubId,
            'handover_to_type' => $handoverToType,
            'handover_to_id' => $handoverToId,
            'note' => $payload['note'] ?? null,
        ]);
    }

    public function createDeliveryTask(array $payload, array $actor, string $tenantMerchantId): array
    {
        $required = ['member_order_id', 'leader_order_id', 'pickup_hub_id', 'driver_user_id', 'driver_type'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new HttpException(422, "{$field} is required");
            }
        }

        $task = $this->repository->createDeliveryTask([
            'tenant_merchant_id' => $tenantMerchantId,
            'member_order_id' => $payload['member_order_id'],
            'leader_order_id' => $payload['leader_order_id'],
            'pickup_hub_id' => $payload['pickup_hub_id'],
            'driver_user_id' => $payload['driver_user_id'],
            'driver_type' => $payload['driver_type'],
            'assigned_by_user_id' => $actor['id'],
        ]);

        $this->repository->updateMemberOrderStatus((string) $payload['member_order_id'], 'out_for_delivery');
        return $task;
    }
    public function listDeliveryTasks(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'member_order_id' => (string) ($query['member_order_id'] ?? ''),
            'leader_order_id' => (string) ($query['leader_order_id'] ?? ''),
            'pickup_hub_id' => (string) ($query['pickup_hub_id'] ?? ''),
            'driver_user_id' => (string) ($query['driver_user_id'] ?? ''),
            'status' => (string) ($query['status'] ?? ''),
        ];

        if (($actor['role'] ?? '') === 'driver') {
            $filters['driver_user_id'] = (string) $actor['id'];
        }

        $limit = (int) ($query['limit'] ?? 50);
        return $this->repository->listDeliveryTasks($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }
    public function updateDeliveryStatus(string $taskId, array $payload): array
    {
        $task = $this->repository->findDeliveryTask($taskId);
        if ($task === null) {
            throw new HttpException(404, 'Delivery task not found');
        }

        $status = (string) ($payload['status'] ?? '');
        $allowed = ['pending_assignment', 'assigned', 'accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Invalid delivery task status');
        }

        $this->repository->updateDeliveryStatus($taskId, $status, $payload['fail_reason'] ?? null);

        if ($status === 'delivered') {
            $this->repository->updateMemberOrderStatus((string) $task['member_order_id'], 'delivered');
        }

        return $this->repository->findDeliveryTask($taskId) ?? $task;
    }
}

