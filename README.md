# Fresh2U (Leader-Driven Group Buying SaaS)

Modular monolith implementation using **PHP 8.5 + PostgreSQL**.

This project follows `AGENTS.md` as source of truth and implements:
- API-first headless backend
- multi-tenant boundary by merchant
- unified wallet (`owner_type + owner_id`) with 3 balance buckets
- recharge / cash recharge / wallet payment / coupon flow
- fulfillment handover chain (merchant -> pickup hub -> driver -> customer)
- earnings tables + settlement posting + ledger entries
- withdrawal workflow with frozen balance

## 1) Architecture Plan

See the complete plan and exact enums in:
- `docs/architecture-plan.md`

## 2) Project Structure

```text
public/        API entry + frontend pages
src/           Shared + Modules (Auth, Products, Orders, Wallet, Coupons, Fulfillment, Accounting, Settlements, Withdrawals)
database/      SQL migrations + seeds
bin/           migrate.php + seed.php
config/        app + database + routes
```

## 3) Tech Rules Enforced

- Backend language: PHP only
- Database: PostgreSQL only
- No microservices (modular monolith)
- Thin controllers, service-layer business logic
- No direct wallet balance mutations without `wallet_transactions`
- No pending earnings moved into wallet before settlement posting

## 4) Setup

1. Copy env:
```bash
cp .env.example .env
```

2. Update `.env` values for PostgreSQL connection.

3. Run migrations:
```bash
php bin/migrate.php
```

4. Run seeds:
```bash
php bin/seed.php
```

5. Start server:
```bash
php -S 127.0.0.1:8080 -t public
```

## 5) Seed Credentials

All seeded users use password: `pass123456`

- `admin@fresh2u.local`
- `merchant1@fresh2u.local`
- `leader1@fresh2u.local`
- `customer1@fresh2u.local`
- `hub1@fresh2u.local`
- `driver1@fresh2u.local`
- `supplier1@fresh2u.local`

## 6) Frontend Role Pages

- `/pages/index.html`
- `/pages/customer.html`
- `/pages/leader.html`
- `/pages/merchant.html`
- `/pages/pickup_hub.html`
- `/pages/driver.html`
- `/pages/supply_partner.html`
- `/pages/admin.html`

These pages call APIs directly and keep backend headless.

## 7) API Highlights

- Auth: `/api/v1/auth/*`
- Product/campaign: `/api/v1/products`, `/api/v1/campaigns`
- Orders: `/api/v1/member-orders`, `/api/v1/leader-orders/*`
- Wallet/recharge: `/api/v1/wallets/*`, `/api/v1/recharges/*`
- Coupons: `/api/v1/coupons/*`
- Fulfillment/delivery: `/api/v1/leader-orders/{id}/route-fulfillment`, `/api/v1/pickup-hubs/*`, `/api/v1/delivery-tasks/*`
- Earnings/settlement/ledger: `/api/v1/earnings/calculate`, `/api/v1/settlements/*`, `/api/v1/ledger-entries`
- Withdrawals: `/api/v1/withdrawals/*`

## 8) Implementation Sequence Completed

1. auth and roles
2. products and leader campaigns
3. member_orders
4. leader_orders
5. unified wallet core
6. recharge_orders and cash recharge
7. wallet payment and payment allocation
8. coupons
9. merchant fulfillment and pickup hub flow
10. delivery_tasks
11. earnings tables
12. ledger_entries and settlements
13. withdrawal workflow
14. responsive frontend pages
15. seed data
16. README

## 9) Notes

- External payment is modeled as API-side callback/simulation in phase 1.
- Customer withdrawal is blocked by service validation (phase 1 rule).
- Settlement posting writes ledger entries and credits wallet balances in the same workflow.
