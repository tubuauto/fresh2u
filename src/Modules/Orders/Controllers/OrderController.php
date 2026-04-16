<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Orders\Services\OrderService;
use App\Shared\Http\Request;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly AuthService $authService,
    ) {
    }

    public function createMemberOrder(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'admin']);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $order = $this->orderService->createMemberOrder($request->body, $user, $tenantMerchantId);
        return [201, ['data' => $order]];
    }
    public function listMemberOrders(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'merchant', 'pickup_hub', 'driver', 'supply_partner', 'admin']);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $orders = $this->orderService->listMemberOrders($user, $tenantMerchantId, $request->query);
        return [200, ['data' => $orders]];
    }

    public function listLeaderOrders(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'merchant', 'pickup_hub', 'supply_partner', 'admin']);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $orders = $this->orderService->listLeaderOrders($user, $tenantMerchantId, $request->query);
        return [200, ['data' => $orders]];
    }
    public function getMemberOrder(Request $request, array $params): array
    {
        $this->authService->requireUser($request);
        $order = $this->orderService->getMemberOrder($params['id']);
        return [200, ['data' => $order]];
    }

    public function applyCoupon(Request $request, array $params): array
    {
        $user = $this->authService->requireUser($request, ['customer', 'leader', 'admin']);
        $order = $this->orderService->applyCoupon($params['id'], $request->body, $user);
        return [200, ['data' => $order]];
    }

    public function payOrder(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['customer', 'leader', 'admin']);
        $order = $this->orderService->payMemberOrder($params['id'], $request->body);
        return [200, ['data' => $order]];
    }

    public function recordCollection(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['leader', 'admin']);
        $order = $this->orderService->recordCollection($params['id'], $request->body);
        return [200, ['data' => $order]];
    }

    public function consolidateLeaderOrder(Request $request): array
    {
        $user = $this->authService->requireUser($request, ['leader', 'admin']);
        $tenantMerchantId = (string) ($request->header('x-tenant-merchant-id') ?? $user['tenant_merchant_id'] ?? '');
        $order = $this->orderService->consolidateLeaderOrder($request->body, $user, $tenantMerchantId);
        return [201, ['data' => $order]];
    }

    public function getLeaderOrder(Request $request, array $params): array
    {
        $this->authService->requireUser($request);
        $order = $this->orderService->getLeaderOrder($params['id']);
        return [200, ['data' => $order]];
    }

    public function submitLeaderOrder(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['leader', 'admin']);
        $order = $this->orderService->submitLeaderOrder($params['id']);
        return [200, ['data' => $order]];
    }

    public function updateLeaderOrderStatus(Request $request, array $params): array
    {
        $this->authService->requireUser($request, ['leader', 'merchant', 'admin', 'pickup_hub']);
        $status = (string) ($request->body['status'] ?? '');
        $order = $this->orderService->updateLeaderOrderStatus($params['id'], $status);
        return [200, ['data' => $order]];
    }
}

