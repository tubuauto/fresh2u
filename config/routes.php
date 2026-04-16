<?php

declare(strict_types=1);

use App\Shared\Http\Router;

return static function (Router $router, array $controllers): void {
    $router->add('GET', '/health', fn() => [200, ['ok' => true]]);

    $router->add('POST', '/api/v1/auth/register', [$controllers['auth'], 'register']);
    $router->add('POST', '/api/v1/auth/login', [$controllers['auth'], 'login']);
    $router->add('GET', '/api/v1/auth/me', [$controllers['auth'], 'me']);

    $router->add('POST', '/api/v1/products', [$controllers['products'], 'createProduct']);
    $router->add('GET', '/api/v1/products', [$controllers['products'], 'listProducts']);
    $router->add('POST', '/api/v1/products/{id}/specs', [$controllers['products'], 'addSpec']);
    $router->add('POST', '/api/v1/campaigns', [$controllers['products'], 'createCampaign']);
    $router->add('GET', '/api/v1/leaders/{leaderId}/campaigns', [$controllers['products'], 'listLeaderCampaigns']);

    $router->add('POST', '/api/v1/member-orders', [$controllers['orders'], 'createMemberOrder']);
    $router->add('GET', '/api/v1/member-orders', [$controllers['orders'], 'listMemberOrders']);
    $router->add('GET', '/api/v1/member-orders/{id}', [$controllers['orders'], 'getMemberOrder']);
    $router->add('POST', '/api/v1/member-orders/{id}/apply-coupon', [$controllers['orders'], 'applyCoupon']);
    $router->add('POST', '/api/v1/member-orders/{id}/pay', [$controllers['orders'], 'payOrder']);
    $router->add('POST', '/api/v1/member-orders/{id}/collection', [$controllers['orders'], 'recordCollection']);

    $router->add('POST', '/api/v1/leader-orders/consolidate', [$controllers['orders'], 'consolidateLeaderOrder']);
    $router->add('GET', '/api/v1/leader-orders', [$controllers['orders'], 'listLeaderOrders']);
    $router->add('GET', '/api/v1/leader-orders/{id}', [$controllers['orders'], 'getLeaderOrder']);
    $router->add('POST', '/api/v1/leader-orders/{id}/submit', [$controllers['orders'], 'submitLeaderOrder']);
    $router->add('POST', '/api/v1/leader-orders/{id}/status', [$controllers['orders'], 'updateLeaderOrderStatus']);

    $router->add('GET', '/api/v1/wallets/{ownerType}/{ownerId}', [$controllers['wallet'], 'getWallet']);
    $router->add('GET', '/api/v1/wallets/{ownerType}/{ownerId}/transactions', [$controllers['wallet'], 'listTransactions']);

    $router->add('POST', '/api/v1/recharges/online', [$controllers['wallet'], 'createOnlineRecharge']);
    $router->add('GET', '/api/v1/recharges', [$controllers['wallet'], 'listRecharges']);
    $router->add('POST', '/api/v1/recharges/{id}/payment-callback', [$controllers['wallet'], 'paymentCallback']);
    $router->add('POST', '/api/v1/recharges/cash', [$controllers['wallet'], 'createCashRecharge']);
    $router->add('POST', '/api/v1/recharges/{id}/confirm-cash', [$controllers['wallet'], 'confirmCashRecharge']);
    $router->add('POST', '/api/v1/recharges/{id}/review', [$controllers['wallet'], 'reviewCashRecharge']);

    $router->add('POST', '/api/v1/coupons', [$controllers['coupons'], 'createCoupon']);
    $router->add('POST', '/api/v1/coupons/{id}/claim', [$controllers['coupons'], 'claimCoupon']);
    $router->add('GET', '/api/v1/coupons/available', [$controllers['coupons'], 'availableCoupons']);

    $router->add('POST', '/api/v1/leader-orders/{id}/route-fulfillment', [$controllers['fulfillment'], 'routeFulfillment']);
    $router->add('POST', '/api/v1/fulfillment/{id}/mark-merchant-fulfilled', [$controllers['fulfillment'], 'markMerchantFulfilled']);
    $router->add('POST', '/api/v1/pickup-hubs/{id}/receipts', [$controllers['fulfillment'], 'recordReceipt']);
    $router->add('POST', '/api/v1/pickup-hubs/{id}/handovers', [$controllers['fulfillment'], 'recordHandover']);

    $router->add('POST', '/api/v1/delivery-tasks', [$controllers['fulfillment'], 'createDeliveryTask']);
    $router->add('GET', '/api/v1/delivery-tasks', [$controllers['fulfillment'], 'listDeliveryTasks']);
    $router->add('POST', '/api/v1/delivery-tasks/{id}/status', [$controllers['fulfillment'], 'updateDeliveryStatus']);

    $router->add('POST', '/api/v1/earnings/calculate', [$controllers['accounting'], 'calculateEarnings']);
    $router->add('POST', '/api/v1/settlements', [$controllers['settlements'], 'createSettlement']);
    $router->add('GET', '/api/v1/settlements', [$controllers['settlements'], 'listSettlements']);
    $router->add('GET', '/api/v1/settlements/{id}', [$controllers['settlements'], 'getSettlement']);
    $router->add('POST', '/api/v1/settlements/{id}/review', [$controllers['settlements'], 'reviewSettlement']);
    $router->add('POST', '/api/v1/settlements/{id}/post', [$controllers['settlements'], 'postSettlement']);
    $router->add('GET', '/api/v1/ledger-entries', [$controllers['settlements'], 'listLedgerEntries']);

    $router->add('POST', '/api/v1/withdrawals', [$controllers['withdrawals'], 'createWithdrawal']);
    $router->add('GET', '/api/v1/withdrawals', [$controllers['withdrawals'], 'listWithdrawals']);
    $router->add('POST', '/api/v1/withdrawals/{id}/review', [$controllers['withdrawals'], 'reviewWithdrawal']);
    $router->add('GET', '/api/v1/withdrawals/{id}', [$controllers['withdrawals'], 'getWithdrawal']);
};
