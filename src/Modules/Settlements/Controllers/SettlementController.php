<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Settlements\Services\SettlementService;
use App\Shared\Http\Request;

final class SettlementController
{
    public function __construct(
        private readonly SettlementService $service,
        private readonly AuthService $authService,
    ) {
    }

    public function createSettlement(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['admin', 'merchant']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->createSettlement($request->body, $tenant);
        return [201, ['data' => $data]];
    }
    public function listSettlements(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['admin', 'merchant', 'leader', 'pickup_hub', 'driver', 'supply_partner']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->listSettlements($user, $tenant, $request->query);
        return [200, ['data' => $data]];
    }

    public function getSettlement(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['admin', 'merchant', 'leader', 'pickup_hub', 'driver', 'supply_partner']);
        $data = $this->service->getSettlement($params['id']);
        return [200, ['data' => $data]];
    }
    public function reviewSettlement(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['admin', 'merchant']);
        $action = (string) ($request->body['action'] ?? 'approve');
        $data = $this->service->reviewSettlement($params['id'], $action, (string) $user['id']);
        return [200, ['data' => $data]];
    }

    public function postSettlement(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['admin', 'merchant']);
        $data = $this->service->postSettlement($params['id']);
        return [200, ['data' => $data]];
    }

    public function listLedgerEntries(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['admin', 'merchant', 'leader', 'pickup_hub', 'driver', 'supply_partner']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->listLedgerEntries($tenant, (int) ($request->query['limit'] ?? 50));
        return [200, ['data' => $data]];
    }
}

