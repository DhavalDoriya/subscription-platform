# Billing Platform: project instructions

Multi-provider subscription billing platform. Providers (brands) get a subdomain storefront and sell plans to customers via Stripe Connect (direct charges). Product spec: `docs/PRD.md` (source of truth; update it when a decision changes).

## Stack
- Backend: Laravel 13, PHP 8.4, PostgreSQL 16, Redis, Horizon. REST JSON API under `/api/v1`.
- Frontend: React + TypeScript + Vite in `resources/js` (separate SPA, **no Inertia**). One Blade view (`resources/views/spa.blade.php`) hosts it.
- Auth: JWT access token (15 min) + rotating refresh token (`php-open-source-saver/jwt-auth`). Not Sanctum, not Passport.
- Payments: Stripe Connect, direct charges, own retry engine (Stripe auto-retries off).
- Tests: Pest (API), Vitest + Testing Library + MSW (web), Playwright (E2E).

## Non-negotiable conventions
1. **TDD.** Write the failing test first, then the code, then refactor. Name tests with PRD ids (e.g. `FR-R3 hard decline is not retried`). Work follows the phases in PRD section 9; don't start a later phase early.
2. **UUID for every id and foreign key.** Use `$table->uuid('id')->primary()` / `foreignUuid()`, and `HasUuids` on models. Stubs in `stubs/` already do this for `make:model` and `make:migration`. Only framework queue tables (`jobs`, `job_batches`) are exempt.
3. **Soft deletes on every business table.** Financial records are never hard-deleted. The activity log is append-only.
4. Money is integer minor units plus a currency code. Timestamps are UTC.
5. **Tenant isolation:** every provider-owned table has `provider_id` and the global tenant scope. Add an isolation test for each new tenant table.
6. Providers only ever see customers who actually subscribed (`provider_customers`), never the global user table.
7. Stripe calls go through one client wrapper (fakeable in tests) and always use idempotency keys. Webhooks are verified, stored in `webhook_events`, and processed idempotently.
8. Secrets (Stripe keys, mail credentials) are stored encrypted and never returned by the API.

## Generate, don't hand-write
Use artisan generators so files follow our stubs:
```sh
php artisan make:model Plan -mf          # uuid + soft deletes via stubs
php artisan make:controller Api/V1/PlanController --api --model=Plan
php artisan make:request StorePlanRequest
php artisan make:resource PlanResource
php artisan make:policy PlanPolicy --model=Plan
php artisan make:job RetryInvoicePayment
php artisan make:test --pest Feature/Plans/CreatePlanTest
```
Frontend scaffolding: shadcn components via `npx shadcn@latest add <component>` (once initialised). Follow the layout below.

## Layout
```
app/                     Laravel code (Models, Http/Controllers/Api/V1, Services, Jobs, Policies)
docs/PRD.md              product requirements and phases
docker/, compose.yaml    local stack (php-fpm, nginx wildcard, node, postgres, redis, horizon, scheduler, mailpit, minio)
resources/js/app/        App shell, router, providers
resources/js/areas/      admin/ provider/ customer/ storefront/  (one folder per SPA area)
resources/js/components/ shared UI (generic table, dialog, form fields)
resources/js/lib/        api client, auth store, utils
tests/                   Pest tests
.claude/skills/build-admin-panel/   method for the admin UI (see below)
```

## Commands
```sh
make up              # docker compose up --build
make test            # pest + vitest inside containers
./vendor/bin/pest    # API tests on host (sqlite in-memory per phpunit.xml)
npm test             # web unit tests
npm run typecheck    # tsc --noEmit
npm run build        # production build
php artisan migrate:fresh --seed
```
A task is done only when `pest`, `npm run typecheck`, `npm test` and `npm run build` pass.

## API conventions
- Versioned `/api/v1`; route groups `public`, `customer`, `provider`, `admin`.
- Use the shared layer, never hand-roll responses: `App\Support\Api\ApiResponse` (success/created/noContent/paginated), `ApiFormRequest` / `ListRequest` (page, per_page max 100, search, sort allow-list, direction), `ApiException` for business errors (message, status, code, errors). Envelope: `{ "data" }` for items, `{ "data": [], "meta": { current_page, per_page, total, last_page } }` for lists, `{ "message", "errors"?, "code"? }` for errors. All `/api/*` exceptions are rendered by `ApiExceptionRenderer`; tests in `tests/Feature/Api/ApiEnvelopeTest.php`.
- Frontend: use `resources/js/lib/api` (`api.get/list/post/put/patch/delete`, `ApiError`, `setAccessTokenGetter`) for every request; do not call axios directly.
- **No hard-coded user-facing text in PHP.** Every API message goes through `__('api.<group>.<key>')` in `lang/en/api.php` (validation text in `lang/en/validation.php`). Clients pick a language with `Accept-Language`; supported locales come from `APP_SUPPORTED_LOCALES` (middleware `SetLocale`). Adding a language = add `lang/<locale>/api.php` and the locale to the env var.
- Validation in Form Requests; authorization in Policies; no logic in controllers beyond orchestration.
- OpenAPI docs via Scramble (`/docs/api`).

## Admin UI
Build the admin panel following `.claude/skills/build-admin-panel/SKILL.md`: sidebar grouped by domain, one service-hook file + one page per entity, a single generic table and dialog reused everywhere, seed the first admin early, and verify with a real Playwright run (login, every page, one write, logout, no console errors or failed requests). Never run `vite build` while `vite dev` is running in the same directory.

## Gotchas
- Billing tests: use the helpers in `tests/Pest.php` (`liveStorefront`, `customerSession`, `subscribe`, `onStorefront`, `asGuest`). A guest request after a login needs `asGuest()`, not just `withoutToken()`.
- Demo data for local use and the billing Playwright spec: `php artisan db:seed --class=DemoBillingSeeder` (refuses to run on production).
- Local tenant domains need `*.platform.test` pointing at 127.0.0.1 (dnsmasq or /etc/hosts entries per slug).
- Refresh-token cookie is scoped to `/api/v1/auth` with `Domain=.platform.com` (`.platform.test` locally).
- Seeding the first admin is a separate step from deploying (`php artisan app:create-admin`, added in Phase 1).
- Stripe webhooks locally: `docker compose --profile stripe up stripe-cli`.
