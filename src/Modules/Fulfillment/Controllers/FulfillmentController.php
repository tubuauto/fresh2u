<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Fulfillment\Services\FulfillmentService;
use App\Shared\Http\Request;

final class FulfillmentController
{
    public function __construct(
        private readonly FulfillmentService $service,
        private readonly AuthService $authService,
    ) {
    }

    public function routeFulfillment(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['merchant', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->routeFulfillment($request->body, $user, $tenant, $params['id']);
        return [201, ['data' => $data]];
    }

    public function markMerchantFulfilled(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['merchant', 'supply_partner', 'admin']);
        $data = $this->service->markMerchantFulfilled($params['id']);
        return [200, ['data' => $data]];
    }

    public function recordReceipt(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['pickup_hub', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->recordReceipt($params['id'], $request->body, $user, $tenant);
        return [201, ['data' => $data]];
    }

    public function recordHandover(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['pickup_hub', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->recordHandover($params['id'], $request->body, $tenant);
        return [201, ['data' => $data]];
    }

    public function createDeliveryTask(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'pickup_hub', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->createDeliveryTask($request->body, $user, $tenant);
        return [201, ['data' => $data]];
    }
    public function listDeliveryTasks(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'pickup_hub', 'driver', 'merchant', 'admin']);
        $tenant = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $data = $this->service->listDeliveryTasks($user, $tenant, $request->query);
        return [200, ['data' => $data]];
    }
    public function updateDeliveryStatus(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['driver', 'leader', 'admin']);
        $data = $this->service->updateDeliveryStatus($params['id'], $request->body);
        return [200, ['data' => $data]];
    }
}

