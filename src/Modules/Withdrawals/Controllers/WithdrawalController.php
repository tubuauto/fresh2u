<?php

declare(strict_types=1);

namespace App\Modules\Withdrawals\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Withdrawals\Services\WithdrawalService;
use App\Shared\Http\Request;

final class WithdrawalController
{
    public function __construct(
        private readonly WithdrawalService $service,
        private readonly AuthService $authService,
    ) {
    }

    public function createWithdrawal(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'merchant', 'pickup_hub', 'driver', 'supply_partner', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->createWithdrawal($request->body, $user, $tenant);
        return [201, ['data' => $data]];
    }
    public function listWithdrawals(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['admin', 'merchant', 'leader', 'pickup_hub', 'driver', 'supply_partner']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->listWithdrawals($user, $tenant, $request->query);
        return [200, ['data' => $data]];
    }
    public function reviewWithdrawal(Request $request, array $params): array
    {
        $reviewer = $this->authService->requireUser($request, ['admin', 'merchant']);
        $data = $this->service->reviewWithdrawal($params['id'], $request->body, $reviewer);
        return [200, ['data' => $data]];
    }

    public function getWithdrawal(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['admin', 'merchant', 'leader', 'pickup_hub', 'driver', 'supply_partner']);
        $data = $this->service->getWithdrawal($params['id']);
        return [200, ['data' => $data]];
    }
}

