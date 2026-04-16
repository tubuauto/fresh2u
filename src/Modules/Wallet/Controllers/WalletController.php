<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Wallet\Services\RechargeService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Http\Request;

final class WalletController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly RechargeService $rechargeService,
        private readonly AuthService $authService,
    ) {
    }

    public function getWallet(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');

        $wallet = $this->walletService->getWallet($tenantMerchantId, $params['ownerType'], $params['ownerId']);
        return [200, ['data' => $wallet]];
    }

    public function listTransactions(Request $request, array $params): array
    {
        $this->authService->requireUser($request);
        $transactions = $this->walletService->listTransactions($params['ownerType'], $params['ownerId'], (int) ($request->query['limit'] ?? 50));
        return [200, ['data' => $transactions]];
    }

    public function createOnlineRecharge(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'admin', 'merchant', 'pickup_hub']);
        $order = $this->rechargeService->createOnlineRecharge($request->body, $user);
        return [201, ['data' => $order]];
    }
    public function listRecharges(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'merchant', 'pickup_hub', 'admin']);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $orders = $this->rechargeService->listRecharges($user, $tenantMerchantId, $request->query);
        return [200, ['data' => $orders]];
    }
    public function paymentCallback(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['admin', 'merchant']);
        $order = $this->rechargeService->paymentCallback($params['id']);
        return [200, ['data' => $order]];
    }

    public function createCashRecharge(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'merchant', 'pickup_hub', 'admin']);
        $order = $this->rechargeService->createCashRecharge($request->body, $user);
        return [201, ['data' => $order]];
    }

    public function confirmCashRecharge(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'merchant', 'pickup_hub', 'admin']);
        $order = $this->rechargeService->confirmCash($params['id'], $request->body, $user);
        return [200, ['data' => $order]];
    }

    public function reviewCashRecharge(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['merchant', 'admin']);
        $order = $this->rechargeService->reviewCash($params['id'], $request->body, $user);
        return [200, ['data' => $order]];
    }
}

