<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Coupons\Services\CouponService;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Support\Exceptions\HttpException;
use PDO;

final class OrderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrderRepository $orderRepository,
        private readonly WalletService $walletService,
        private readonly CouponService $couponService,
        private readonly string $currency,
    ) {
    }

    public function createMemberOrder(array $payload, array $actor, string $tenantMerchantId): array
    {
        $customerUserId = (string) ($payload['customer_user_id'] ?? ($actor['role'] === 'customer' ? $actor['id'] : ''));
        $leaderUserId = (string) ($payload['leader_user_id'] ?? $actor['bound_leader_user_id'] ?? '');

        if ($customerUserId === '' || $leaderUserId === '') {
            throw new HttpException(422, 'customer_user_id and leader_user_id are required');
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new HttpException(422, 'At least one item is required');
        }

        $normalizedItems = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            if ($qty <= 0 || $unitPrice < 0) {
                throw new HttpException(422, 'Invalid item qty or unit_price');
            }

            $lineAmount = round($qty * $unitPrice, 2);
            $subtotal += $lineAmount;

            $normalizedItems[] = [
                'product_id' => (string) $item['product_id'],
                'product_spec_id' => $item['product_spec_id'] ?? null,
                'product_name' => (string) ($item['product_name'] ?? 'Product'),
                'qty' => $qty,
                'unit_price' => number_format(round($unitPrice, 2), 2, '.', ''),
                'line_amount' => number_format($lineAmount, 2, '.', ''),
            ];
        }

        return $this->orderRepository->createMemberOrder([
            'order_no' => 'MOR-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'tenant_merchant_id' => $tenantMerchantId,
            'leader_user_id' => $leaderUserId,
            'customer_user_id' => $customerUserId,
            'placed_by_type' => $actor['role'],
            'placed_by_user_id' => $actor['id'],
            'status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'collection_status' => 'not_collected',
            'subtotal_amount' => number_format(round($subtotal, 2), 2, '.', ''),
            'coupon_discount_amount' => '0.00',
            'payable_amount' => number_format(round($subtotal, 2), 2, '.', ''),
            'wallet_deducted_amount' => '0.00',
            'external_paid_amount' => '0.00',
            'currency' => $this->currency,
        ], $normalizedItems);
    }

    public function getMemberOrder(string $id): array
    {
        $order = $this->orderRepository->findMemberOrder($id);
        if ($order === null) {
            throw new HttpException(404, 'Member order not found');
        }

        $items = $this->orderRepository->memberOrderItems($id);
        $order['items'] = $items;
        return $order;
    }
    public function listMemberOrders(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'leader_user_id' => (string) ($query['leader_user_id'] ?? ''),
            'customer_user_id' => (string) ($query['customer_user_id'] ?? ''),
            'status' => (string) ($query['status'] ?? ''),
            'payment_status' => (string) ($query['payment_status'] ?? ''),
            'collection_status' => (string) ($query['collection_status'] ?? ''),
        ];

        if (($actor['role'] ?? '') === 'customer') {
            $filters['customer_user_id'] = (string) $actor['id'];
        }

        if (($actor['role'] ?? '') === 'leader' && $filters['leader_user_id'] === '') {
            $filters['leader_user_id'] = (string) $actor['id'];
        }

        $limit = (int) ($query['limit'] ?? 50);

        return $this->orderRepository->listMemberOrders($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }

    public function listLeaderOrders(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'merchant_id' => (string) ($query['merchant_id'] ?? ''),
            'leader_user_id' => (string) ($query['leader_user_id'] ?? ''),
            'status' => (string) ($query['status'] ?? ''),
        ];

        if (($actor['role'] ?? '') === 'leader' && $filters['leader_user_id'] === '') {
            $filters['leader_user_id'] = (string) $actor['id'];
        }

        $limit = (int) ($query['limit'] ?? 50);

        return $this->orderRepository->listLeaderOrders($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }
    public function applyCoupon(string $memberOrderId, array $payload, array $actor): array
    {
        $order = $this->getMemberOrder($memberOrderId);
        if ((string) $order['payment_status'] === 'paid') {
            throw new HttpException(422, 'Cannot apply coupon on paid order');
        }

        $couponId = (string) ($payload['coupon_id'] ?? '');
        $claimId = (string) ($payload['claim_id'] ?? '');
        if ($couponId === '' || $claimId === '') {
            throw new HttpException(422, 'coupon_id and claim_id are required');
        }

        $result = $this->couponService->applyCouponToOrder(
            (string) $order['tenant_merchant_id'],
            $memberOrderId,
            (string) $order['customer_user_id'],
            $couponId,
            $claimId,
            (float) $order['subtotal_amount']
        );

        $payable = max(0, round((float) $order['subtotal_amount'] - (float) $result['discount_amount'], 2));

        $this->orderRepository->updateMemberOrderCoupon(
            $memberOrderId,
            $couponId,
            $claimId,
            number_format((float) $result['discount_amount'], 2, '.', ''),
            number_format($payable, 2, '.', '')
        );

        return $this->getMemberOrder($memberOrderId);
    }

    public function payMemberOrder(string $memberOrderId, array $payload): array
    {
        $order = $this->getMemberOrder($memberOrderId);

        if ((string) $order['payment_status'] === 'paid') {
            return $order;
        }

        $payable = (float) $order['payable_amount'];
        if ($payable <= 0) {
            $this->orderRepository->updateMemberOrderPayment($memberOrderId, [
                'payment_status' => 'paid',
                'status' => 'paid',
                'wallet_deducted_amount' => number_format((float) $order['wallet_deducted_amount'], 2, '.', ''),
                'external_paid_amount' => number_format((float) $order['external_paid_amount'], 2, '.', ''),
            ]);
            return $this->getMemberOrder($memberOrderId);
        }

        $allocation = $this->walletService->consumeByPriority(
            (string) $order['tenant_merchant_id'],
            'customer',
            (string) $order['customer_user_id'],
            $payable,
            'member_order',
            $memberOrderId,
            (string) $order['order_no']
        );

        foreach ($allocation['allocations'] as $walletAllocation) {
            $this->orderRepository->addPaymentAllocation(
                (string) $order['tenant_merchant_id'],
                $memberOrderId,
                (string) $walletAllocation['source'],
                number_format((float) $walletAllocation['amount'], 2, '.', ''),
                (string) $order['currency']
            );
        }

        $settleExternal = (bool) ($payload['settle_external'] ?? true);
        $externalRemaining = (float) $allocation['external_remaining'];
        $externalPaid = 0.0;
        $paymentStatus = 'partially_paid';
        $status = 'awaiting_payment';

        if ($externalRemaining > 0 && $settleExternal) {
            $externalPaid = $externalRemaining;
            $this->orderRepository->addPaymentAllocation(
                (string) $order['tenant_merchant_id'],
                $memberOrderId,
                'external',
                number_format($externalRemaining, 2, '.', ''),
                (string) $order['currency']
            );
            $paymentStatus = 'paid';
            $status = 'paid';
        } elseif ($externalRemaining <= 0) {
            $paymentStatus = 'paid';
            $status = 'paid';
        }

        $this->orderRepository->updateMemberOrderPayment($memberOrderId, [
            'payment_status' => $paymentStatus,
            'status' => $status,
            'wallet_deducted_amount' => number_format((float) $allocation['wallet_paid'], 2, '.', ''),
            'external_paid_amount' => number_format($externalPaid, 2, '.', ''),
        ]);

        return $this->getMemberOrder($memberOrderId);
    }

    public function recordCollection(string $memberOrderId, array $payload): array
    {
        $order = $this->getMemberOrder($memberOrderId);

        $this->orderRepository->updateMemberOrderCollection($memberOrderId, [
            'collection_status' => $payload['collection_status'] ?? 'pending_offline_collection',
            'collection_method' => $payload['collection_method'] ?? 'leader_collection',
            'collected_amount' => number_format(round((float) ($payload['collected_amount'] ?? 0), 2), 2, '.', ''),
            'collected_at' => $payload['collected_at'] ?? date(DATE_ATOM),
            'collection_note' => $payload['collection_note'] ?? null,
        ]);

        return $this->getMemberOrder($memberOrderId);
    }

    public function consolidateLeaderOrder(array $payload, array $actor, string $tenantMerchantId): array
    {
        $memberOrderIds = $payload['member_order_ids'] ?? [];
        if (!is_array($memberOrderIds) || count($memberOrderIds) === 0) {
            throw new HttpException(422, 'member_order_ids is required');
        }

        $orders = $this->orderRepository->getMemberOrdersForConsolidation($tenantMerchantId, $memberOrderIds);
        if (count($orders) !== count($memberOrderIds)) {
            throw new HttpException(422, 'Some member orders were not found in tenant scope');
        }

        $merchantId = (string) ($payload['merchant_id'] ?? $tenantMerchantId);
        $leaderUserId = (string) ($payload['leader_user_id'] ?? $actor['id']);
        $subtotal = 0.0;
        $discount = 0.0;
        $payable = 0.0;

        foreach ($orders as $order) {
            if ((string) $order['payment_status'] !== 'paid') {
                throw new HttpException(422, 'All member orders must be paid before consolidation');
            }

            $subtotal += (float) $order['subtotal_amount'];
            $discount += (float) $order['coupon_discount_amount'];
            $payable += (float) $order['payable_amount'];
        }

        $this->pdo->beginTransaction();
        try {
            $leaderOrder = $this->orderRepository->createLeaderOrder([
                'order_no' => 'LOR-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
                'tenant_merchant_id' => $tenantMerchantId,
                'merchant_id' => $merchantId,
                'leader_user_id' => $leaderUserId,
                'pickup_hub_id' => $payload['pickup_hub_id'] ?? null,
                'status' => 'draft',
                'subtotal_amount' => number_format(round($subtotal, 2), 2, '.', ''),
                'coupon_discount_amount' => number_format(round($discount, 2), 2, '.', ''),
                'payable_amount' => number_format(round($payable, 2), 2, '.', ''),
                'currency' => $this->currency,
                'note' => $payload['note'] ?? null,
            ]);

            $this->orderRepository->addLeaderOrderItemsFromMemberOrders($tenantMerchantId, (string) $leaderOrder['id'], $memberOrderIds);
            $this->orderRepository->attachMemberOrdersToLeaderOrder((string) $leaderOrder['id'], $memberOrderIds);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getLeaderOrder((string) $leaderOrder['id']);
    }

    public function getLeaderOrder(string $id): array
    {
        $order = $this->orderRepository->findLeaderOrder($id);
        if ($order === null) {
            throw new HttpException(404, 'Leader order not found');
        }

        return $order;
    }

    public function submitLeaderOrder(string $leaderOrderId): array
    {
        $order = $this->getLeaderOrder($leaderOrderId);
        if (!in_array((string) $order['status'], ['draft', 'submitted'], true)) {
            throw new HttpException(422, 'Leader order cannot be submitted from current status');
        }

        $this->orderRepository->updateLeaderOrderStatus($leaderOrderId, 'submitted');
        return $this->getLeaderOrder($leaderOrderId);
    }

    public function updateLeaderOrderStatus(string $leaderOrderId, string $status): array
    {
        $allowed = [
            'draft', 'submitted', 'confirmed', 'paid', 'routed_to_supply_partner', 'in_fulfillment',
            'merchant_fulfilled', 'at_pickup_hub', 'handed_over', 'completed', 'cancelled',
        ];

        if (!in_array($status, $allowed, true)) {
            throw new HttpException(422, 'Invalid leader order status');
        }

        $this->orderRepository->updateLeaderOrderStatus($leaderOrderId, $status);
        return $this->getLeaderOrder($leaderOrderId);
    }
}

