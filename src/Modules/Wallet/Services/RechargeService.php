<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Services;

use App\Modules\Wallet\Repositories\RechargeRepository;
use App\Shared\Support\Exceptions\HttpException;
use PDO;

final class RechargeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RechargeRepository $rechargeRepository,
        private readonly WalletService $walletService,
        private readonly array $appConfig,
    ) {
    }

    public function createOnlineRecharge(array $payload, array $actor): array
    {
        $tenantMerchantId = (string) ($payload['tenant_merchant_id'] ?? $actor['tenant_merchant_id'] ?? '');
        $customerUserId = (string) ($payload['customer_user_id'] ?? $actor['id']);
        $rechargeAmount = (float) ($payload['recharge_amount'] ?? 0);
        $bonusAmount = (float) ($payload['bonus_amount'] ?? 0);

        if ($tenantMerchantId === '' || $customerUserId === '' || $rechargeAmount <= 0) {
            throw new HttpException(422, 'tenant_merchant_id, customer_user_id and positive recharge_amount are required');
        }

        $wallet = $this->walletService->getWallet($tenantMerchantId, 'customer', $customerUserId);

        return $this->rechargeRepository->create([
            'order_no' => 'RCG-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'tenant_merchant_id' => $tenantMerchantId,
            'customer_user_id' => $customerUserId,
            'wallet_id' => $wallet['id'],
            'currency' => $wallet['currency'],
            'recharge_amount' => number_format(round($rechargeAmount, 2), 2, '.', ''),
            'bonus_amount' => number_format(round($bonusAmount, 2), 2, '.', ''),
            'payable_amount' => number_format(round($rechargeAmount, 2), 2, '.', ''),
            'payment_method' => 'online',
            'payment_status' => 'unpaid',
            'status' => 'awaiting_payment',
            'initiated_by_type' => $actor['role'],
            'initiated_by_id' => $actor['id'],
            'received_by_type' => null,
            'received_by_id' => null,
            'received_at' => null,
            'proof_note' => null,
            'proof_image' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'paid_at' => null,
            'expired_at' => null,
        ]);
    }

    public function paymentCallback(string $id): array
    {
        $order = $this->mustFind($id);
        if ($order['status'] === 'paid') {
            return $order;
        }

        $this->walletService->credit(
            (string) ($order['tenant_merchant_id'] ?? ''),
            'customer',
            (string) $order['customer_user_id'],
            'withdrawable_balance',
            (float) $order['recharge_amount'],
            'recharge_credit',
            'recharge_order',
            (string) $order['id'],
            (string) $order['order_no'],
            'Online recharge credited'
        );

        if ((float) $order['bonus_amount'] > 0) {
            $this->walletService->credit(
                (string) ($order['tenant_merchant_id'] ?? ''),
                'customer',
                (string) $order['customer_user_id'],
                'non_withdrawable_balance',
                (float) $order['bonus_amount'],
                'recharge_bonus_credit',
                'recharge_order',
                (string) $order['id'],
                (string) $order['order_no'],
                'Recharge bonus credited'
            );
        }

        $this->rechargeRepository->updateStatus($id, 'paid', 'paid', null, true);
        return $this->mustFind($id);
    }

    public function createCashRecharge(array $payload, array $actor): array
    {
        $tenantMerchantId = (string) ($payload['tenant_merchant_id'] ?? $actor['tenant_merchant_id'] ?? '');
        $customerUserId = (string) ($payload['customer_user_id'] ?? '');
        $rechargeAmount = (float) ($payload['recharge_amount'] ?? 0);

        if ($tenantMerchantId === '' || $customerUserId === '' || $rechargeAmount <= 0) {
            throw new HttpException(422, 'tenant_merchant_id, customer_user_id and positive recharge_amount are required');
        }

        $wallet = $this->walletService->getWallet($tenantMerchantId, 'customer', $customerUserId);

        return $this->rechargeRepository->create([
            'order_no' => 'CRG-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'tenant_merchant_id' => $tenantMerchantId,
            'customer_user_id' => $customerUserId,
            'wallet_id' => $wallet['id'],
            'currency' => $wallet['currency'],
            'recharge_amount' => number_format(round($rechargeAmount, 2), 2, '.', ''),
            'bonus_amount' => number_format(round((float) ($payload['bonus_amount'] ?? 0), 2), 2, '.', ''),
            'payable_amount' => number_format(round($rechargeAmount, 2), 2, '.', ''),
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'status' => 'awaiting_offline_confirmation',
            'initiated_by_type' => $actor['role'],
            'initiated_by_id' => $actor['id'],
            'received_by_type' => null,
            'received_by_id' => null,
            'received_at' => null,
            'proof_note' => $payload['proof_note'] ?? null,
            'proof_image' => $payload['proof_image'] ?? null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'paid_at' => null,
            'expired_at' => null,
        ]);
    }

    public function confirmCash(string $id, array $payload, array $actor): array
    {
        $order = $this->mustFind($id);
        if ($order['payment_method'] !== 'cash') {
            throw new HttpException(422, 'Order is not a cash recharge');
        }

        $requiresReview = (bool) $this->appConfig['leader_cash_collection_requires_review'];
        $status = $requiresReview && $actor['role'] === 'leader' ? 'awaiting_review' : 'confirmed';

        $stmt = $this->pdo->prepare(
            'UPDATE recharge_orders
            SET status = :status,
                received_by_type = :received_by_type,
                received_by_id = :received_by_id,
                received_at = NOW(),
                proof_note = COALESCE(:proof_note, proof_note),
                proof_image = COALESCE(:proof_image, proof_image),
                updated_at = NOW()
            WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'received_by_type' => $actor['role'],
            'received_by_id' => $actor['id'],
            'proof_note' => $payload['proof_note'] ?? null,
            'proof_image' => $payload['proof_image'] ?? null,
        ]);

        if ($status === 'confirmed') {
            return $this->creditConfirmedCashOrder($id);
        }

        return $this->mustFind($id);
    }

    public function reviewCash(string $id, array $payload, array $actor): array
    {
        $order = $this->mustFind($id);
        if (!in_array($order['status'], ['awaiting_review', 'confirmed'], true)) {
            throw new HttpException(422, 'Recharge order is not reviewable');
        }

        $action = (string) ($payload['action'] ?? 'approve');
        if ($action === 'reject') {
            $this->rechargeRepository->updateStatus($id, 'failed', 'failed', $actor['id'], false);
            return $this->mustFind($id);
        }

        return $this->creditConfirmedCashOrder($id, $actor['id']);
    }
    public function listRecharges(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'customer_user_id' => (string) ($query['customer_user_id'] ?? ''),
            'status' => (string) ($query['status'] ?? ''),
            'payment_method' => (string) ($query['payment_method'] ?? ''),
            'initiated_by_id' => (string) ($query['initiated_by_id'] ?? ''),
            'received_by_id' => (string) ($query['received_by_id'] ?? ''),
        ];

        $role = (string) ($actor['role'] ?? '');
        if ($role === 'customer') {
            $filters['customer_user_id'] = (string) $actor['id'];
        }

        if (in_array($role, ['leader', 'pickup_hub'], true) && $filters['initiated_by_id'] === '' && $filters['received_by_id'] === '') {
            $filters['initiated_by_id'] = (string) $actor['id'];
        }

        $limit = (int) ($query['limit'] ?? 50);
        return $this->rechargeRepository->listByTenant($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }
    private function creditConfirmedCashOrder(string $id, ?string $reviewedBy = null): array
    {
        $order = $this->mustFind($id);

        $this->walletService->credit(
            (string) ($order['tenant_merchant_id'] ?? ''),
            'customer',
            (string) $order['customer_user_id'],
            'withdrawable_balance',
            (float) $order['recharge_amount'],
            'cash_recharge_credit',
            'recharge_order',
            (string) $order['id'],
            (string) $order['order_no'],
            'Cash recharge credited'
        );

        if ((float) $order['bonus_amount'] > 0) {
            $this->walletService->credit(
                (string) ($order['tenant_merchant_id'] ?? ''),
                'customer',
                (string) $order['customer_user_id'],
                'non_withdrawable_balance',
                (float) $order['bonus_amount'],
                'recharge_bonus_credit',
                'recharge_order',
                (string) $order['id'],
                (string) $order['order_no'],
                'Cash recharge bonus credited'
            );
        }

        $this->rechargeRepository->updateStatus($id, 'paid', 'paid', $reviewedBy, true);
        return $this->mustFind($id);
    }

    private function mustFind(string $id): array
    {
        $order = $this->rechargeRepository->findById($id);
        if ($order === null) {
            throw new HttpException(404, 'Recharge order not found');
        }

        return $order;
    }
}

