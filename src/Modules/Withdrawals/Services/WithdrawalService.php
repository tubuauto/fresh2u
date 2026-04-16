<?php

declare(strict_types=1);

namespace App\Modules\Withdrawals\Services;

use App\Modules\Wallet\Services\WalletService;
use App\Modules\Withdrawals\Repositories\WithdrawalRepository;
use App\Shared\Support\Exceptions\HttpException;
use PDO;

final class WithdrawalService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WithdrawalRepository $repository,
        private readonly WalletService $walletService,
    ) {
    }

    public function createWithdrawal(array $payload, array $actor, string $tenantMerchantId): array
    {
        $ownerType = (string) ($payload['owner_type'] ?? $this->ownerTypeFromRole((string) $actor['role']));
        $ownerId = (string) ($payload['owner_id'] ?? $actor['id']);

        if ($ownerType === '' || $ownerId === '') {
            throw new HttpException(422, 'owner_type and owner_id are required');
        }

        if ($ownerType === 'customer') {
            throw new HttpException(403, 'Customer withdrawal is disabled in phase 1');
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new HttpException(422, 'amount must be positive');
        }

        $feeAmount = round((float) ($payload['fee_amount'] ?? 0), 2);
        $netAmount = max(0, round($amount - $feeAmount, 2));

        $wallet = $this->walletService->getWallet($tenantMerchantId, $ownerType, $ownerId);
        $referenceNo = 'WDR-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));

        $this->pdo->beginTransaction();
        try {
            $request = $this->repository->create([
                'tenant_merchant_id' => $tenantMerchantId,
                'wallet_id' => $wallet['id'],
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'amount' => number_format($amount, 2, '.', ''),
                'fee_amount' => number_format($feeAmount, 2, '.', ''),
                'net_amount' => number_format($netAmount, 2, '.', ''),
                'status' => 'pending_review',
                'account_name' => (string) ($payload['account_name'] ?? ''),
                'account_no' => (string) ($payload['account_no'] ?? ''),
                'account_type' => (string) ($payload['account_type'] ?? ''),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'remark' => $payload['remark'] ?? null,
            ]);

            $this->walletService->freezeForWithdrawal(
                $tenantMerchantId,
                $ownerType,
                $ownerId,
                $amount,
                'withdrawal_request',
                (string) $request['id'],
                $referenceNo
            );

            $this->pdo->commit();
            return $this->getWithdrawal((string) $request['id']);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function reviewWithdrawal(string $withdrawalId, array $payload, array $reviewer): array
    {
        $request = $this->getWithdrawal($withdrawalId);
        if ((string) $request['status'] !== 'pending_review') {
            throw new HttpException(422, 'Withdrawal is not pending review');
        }

        $action = (string) ($payload['action'] ?? 'approve');

        $this->pdo->beginTransaction();
        try {
            if ($action === 'reject') {
                $this->walletService->rejectWithdrawal(
                    (string) ($request['tenant_merchant_id'] ?? ''),
                    (string) $request['owner_type'],
                    (string) $request['owner_id'],
                    (float) $request['amount'],
                    'withdrawal_request',
                    (string) $request['id'],
                    'WDR-REJECT-' . substr((string) $request['id'], 0, 8)
                );
                $this->repository->updateStatus($withdrawalId, 'rejected', (string) $reviewer['id'], $payload['remark'] ?? null);
            } else {
                $this->repository->updateStatus($withdrawalId, 'approved', (string) $reviewer['id'], $payload['remark'] ?? null);
                $this->walletService->finalizeWithdrawal(
                    (string) ($request['tenant_merchant_id'] ?? ''),
                    (string) $request['owner_type'],
                    (string) $request['owner_id'],
                    (float) $request['amount'],
                    'withdrawal_request',
                    (string) $request['id'],
                    'WDR-APPROVE-' . substr((string) $request['id'], 0, 8)
                );
                $this->repository->updateStatus($withdrawalId, 'completed', (string) $reviewer['id'], $payload['remark'] ?? null);
            }

            $this->pdo->commit();
            return $this->getWithdrawal($withdrawalId);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    public function listWithdrawals(array $actor, string $tenantMerchantId, array $query): array
    {
        $filters = [
            'owner_type' => (string) ($query['owner_type'] ?? ''),
            'owner_id' => (string) ($query['owner_id'] ?? ''),
            'status' => (string) ($query['status'] ?? ''),
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
        return $this->repository->listByTenant($tenantMerchantId, $filters, max(1, min($limit, 200)));
    }
    public function getWithdrawal(string $withdrawalId): array
    {
        $request = $this->repository->findById($withdrawalId);
        if ($request === null) {
            throw new HttpException(404, 'Withdrawal request not found');
        }

        return $request;
    }

    private function ownerTypeFromRole(string $role): string
    {
        return match ($role) {
            'leader' => 'leader',
            'merchant' => 'merchant',
            'pickup_hub' => 'pickup_hub',
            'driver' => 'driver',
            'supply_partner' => 'supply_partner',
            'customer' => 'customer',
            default => '',
        };
    }
}

