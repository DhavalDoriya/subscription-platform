# Work Plan: Phases 0 to 9

Companion to [PRD.md](PRD.md) (what to build) and [../CLAUDE.md](../CLAUDE.md) (how we build). This file breaks the phases into tasks that can be done and checked off one at a time.

## How to use this plan

- Work phases in order. Don't start a phase until the previous one's **exit criteria** are met.
- Inside a phase, every task follows TDD: write the failing test (red), make it pass (green), refactor.
- Each task references PRD requirement ids (FR-xx) so test names can quote them.
- Every phase ends with the full suite green (`pest`, `npm test`, `npm run typecheck`, `npm run build`) and, for UI phases, a real-browser Playwright run (login, every page, one write, logout, no console errors or failed requests).
- Sizes are rough effort for one developer: **S** under half a day, **M** about 1 day, **L** 2 to 3 days.

## Carried over (not done yet)

Open items from earlier phases. Revisit them at the next phase boundary; none block Phase 2 work.

- **T0.1** Docker stack up, Pest and Vitest inside containers. Needs a running Docker daemon (missing in the dev sandbox).
- **T0.2** Wildcard domain `*.platform.test` resolving to nginx. Needs hosts or dnsmasq entries. Phase 2 tenant resolution is covered by tests that fake the Host header; a real-browser check on a subdomain waits for this.
- **T0.3** CI runs Pest on Postgres 16 as well, but this has not been run yet (no Postgres locally). Confirm on the first CI run or `make test`.
- **ESLint** Deferred: `typescript-eslint` does not support TypeScript 7 yet. Pin TypeScript 6 or wait.
- **Stripe retry suppression** (`auto_advance=false`) is desk research only; verify with test clocks in T6.4.
- **Real Stripe test-mode walkthrough** (Phase 3 exit criterion): needs `sk_test_` key and the two webhook secrets saved in admin Settings, then connect a provider, publish a plan and replay a webhook. Everything so far runs against `FakeStripeGateway`, so check the real SDK v22 calls (`accounts->create`, `accountLinks->create`, `products/prices` with `stripe_account`) and pin an API version.
- Nothing is committed yet. Ask before committing.

## Overview

| Phase | Name | Depends on | Size | Output |
|---|---|---|---|---|
| 0 | Foundation | none | M | Repo, Docker, test tooling, conventions (mostly done) |
| 1 | Auth (JWT + refresh) and RBAC | 0 | L | Register, login, refresh rotation, roles, first admin |
| 2 | Tenancy, providers, admin core | 1 | L | Provider signup, approval, admin shell, listings, settings |
| 3 | Stripe Connect and plans | 2 | L | Connect onboarding, plans and prices, webhook base |
| 4 | Storefront | 2, 3 | L | Subdomain page, content editor, publishing |
| 5 | Checkout and subscriptions | 3, 4 | L | Join flow, checkout, lifecycle, invoices, portals |
| 6 | Retry and dunning engine | 5 | L | Retry schedule, decline rules, grace, pause on deactivation |
| 7 | Email templates and mail config | 1 (needed by 6 for real sends) | M | Template manager, mail credentials, delivery log |
| 8 | Commission v1 | 5 | M | Rates, application fee, commission records and views |
| 9 | Hardening and launch | all | M | Reports, security review, E2E sweep, deploy decision |

Phase 7 can run in parallel with Phase 6 after Phase 5. Phase 6 uses stub mail until 7 lands.

---

## Phase 0: Foundation

**Goal:** a working, tested skeleton that every later phase builds on.

Status: mostly done. Remaining tasks are marked open.

- [x] Laravel 13 app, Postgres-ready config, Pest, packages installed (jwt-auth, permission, activitylog, Horizon, Scramble, stripe-php).
- [x] React + TypeScript + Vite in `resources/js`, SPA shell route, Vitest + Testing Library.
- [x] UUID primary and foreign keys, soft deletes, custom stubs, custom Role/Permission/Activity models.
- [x] Docker files (`compose.yaml`, php, nginx wildcard), Makefile, CI workflow.
- [x] `CLAUDE.md`, `AGENTS.md`, README, PRD.
- [ ] **T0.1** Bring the Docker stack up and run Pest and Vitest inside containers (M).
- [ ] **T0.2** Wildcard local domain: `platform.test` and `*.platform.test` resolve to nginx; a test slug serves the SPA (S).
- [ ] **T0.3** Run the test suite against Postgres (not only sqlite) in CI and locally (S). CI now runs Pest on both; **not yet verified**: no Postgres or Docker daemon in the dev sandbox, first CI run or `make test` will confirm.
- [x] **T0.4** Initialize shadcn/ui and add base components (button, input, table, dialog, toast, form) (S). Done with the `base-nova` style; tokens mapped to our palette in `resources/css/app.css`. Note: the CLI generated `import ... from "cn"` (an unrelated npm package) and overwrites edited components on `add --overwrite`; `cn` lives in `resources/js/lib/utils.ts`, and `button.tsx` has our `loading` prop.
- [x] **T0.5** Playwright config plus a smoke test that loads the shell (S). Specs: `tests/e2e/auth.spec.ts`, `profile.spec.ts`; run with the app served on :8000.
- [x] **T0.6** Spike: can Stripe automatic retries be disabled on connected accounts? Finding in PRD section 9 (Phase 0): not controllable per connected account; fallback is `auto_advance=false` per invoice plus engine-paid retries; verify with test clocks in T6.4 (S).
- [x] **T0.7** Lint and format: Pint for PHP, Prettier for web, wired into CI (S). ESLint is **deferred**: `typescript-eslint` does not support TypeScript 7 yet (peer range below 6.1); add it when it does, or pin TypeScript 6.

**Exit criteria:** `make up` works from a clean clone; both test suites and CI are green on Postgres; spike result recorded.

---

## Phase 1: Auth (JWT + refresh) and RBAC

**Goal:** secure login for customers, providers and admins. Covers FR-A1 to FR-A5, FR-A8.

Tests first:
- login returns an access token and sets the refresh cookie
- an expired access token is refreshed and the old refresh token is revoked
- a reused refresh token revokes the whole family
- logout blacklists the access token `jti`
- throttling and lockout after repeated failures
- role guards block cross-role access

Tasks:
- [x] **T1.1** `refresh_tokens` migration and model (uuid, hashed token, family id, device, ip, expires, revoked, replaced_by) (S).
- [x] **T1.2** Configure the `api` JWT guard, token claims (`sub`, `roles`, `jti`), 15 min TTL (S).
- [x] **T1.3** Endpoints: register, login, refresh, logout, logout-all, me (M).
- [x] **T1.4** Refresh rotation with reuse detection, cookie settings (httpOnly, Secure, SameSite, path, domain) (M).
- [x] **T1.5** Email verification and password reset (signed links, throttled); password change revokes all families (M).
- [x] **T1.6** Roles and permissions seeder (customer, provider_owner, admin, super_admin) and policies base (S).
- [x] **T1.7** `php artisan app:create-admin` command for the first admin (S).
- [x] **T1.8** Rate limiting and lockout (S).
- [x] **T1.9** Frontend: auth store (token in memory), Axios client with single-flight refresh on 401, login/register/reset pages, route guards by role (L).
- [x] **T1.10** Profile page: name, email, password, avatar (FR-A8) (S).
- [x] **T1.11** Define and pin the API response envelope in a test (`data`, `meta`, error shape) (S).

**Exit criteria:** a user can register, verify email, log in, stay signed in across token expiry, and log out everywhere; an admin can be seeded; guards proven by tests; Playwright login/logout passes.

---

## Phase 2: Tenancy, providers and admin core

**Goal:** providers can register and be approved; admins can see and manage them. Covers FR-P1 to FR-P3, FR-P7, FR-AD1 to FR-AD4, FR-AD7, FR-AD9, FR-AD10, FR-L1.

Tests first:
- slug rules: length, charset, reserved words, uniqueness, immutable after approval
- tenant scope: provider A can never read provider B rows
- status transitions (pending, approved, active, inactive, suspended, rejected) and who may perform them
- auto-approve setting (default on) changes the outcome of registration
- settings: secrets stored encrypted and never returned
- list endpoints return the pinned envelope with search, filter, sort and pagination

Tasks:
- [x] **T2.1** `providers`, `provider_users`, `provider_customers` migrations and models; base `TenantOwned` trait with global scope (M).
- [x] **T2.2** Tenant resolver middleware from subdomain, with reserved-subdomain blocklist (M).
- [x] **T2.3** Provider registration endpoint, slug availability check, auto-approve setting (M).
- [x] **T2.4** Admin provider actions: approve, reject with reason, activate, deactivate, suspend, edit slug (M).
- [x] **T2.5** `settings` store: grouped keys, validation, caching, encrypted values; admin settings API (general, provider rules, currencies, security) (M).
- [x] **T2.6** Audit log wiring (activitylog) for admin and provider mutations; admin audit viewer API (S).
- [x] **T2.7** Admin shell UI: sidebar grouped by domain, generic data table, generic dialog, toast pattern, layout (L).
- [x] **T2.8** Admin dashboard (provider counts by status, customers, recent signups) (M). API and UI done (real-browser checked).
- [x] **T2.9** Providers listing page and detail dialog with active/inactive control (M). API and UI done: list with search/status filter/sort/paging, detail dialog, lifecycle actions, slug edit.
- [x] **T2.10** Customers listing page with block/unblock (FR-AD3) (M). API done (`/admin/customers`, block/unblock; blocked users are refused at login, refresh and on live tokens); UI done.
- [x] **T2.11** Admin user management for super admin (FR-AD9) (S). API and UI done (`/admin/admins`).
- [x] **T2.12** Provider registration UI and provider dashboard shell with branding settings (M). Done: `/become-a-provider` (live address check, currencies from the admin list), provider dashboard shell (`/dashboard`, status banner, `/dashboard/settings` for contact, timezone, currency, brand colour, legal links), `PATCH /provider`, `GET /public/config`. The owner cannot change slug, status or Stripe fields. Logo and favicon upload wait for the media library (T4.4).
- [x] **T2.13** Impersonation with audit trail and exit banner (FR-A10) (M). API done (`POST /admin/users/{id}/impersonate`, `POST /auth/impersonation/end`; token carries `imp`, no refresh, credential changes blocked); UI done: Impersonate in Customers, banner with Exit.
- [x] **T2.14** Tenant-isolation test helper reused by every later tenant table (S).

**Exit criteria:** a brand registers, is auto-approved, gets a slug; admin can list, filter and activate/deactivate providers and customers; isolation tests pass; Playwright sweep of admin pages passes. **Met** (Pest 170, Vitest 110, Playwright 5 specs).

---

## Phase 3: Stripe Connect and plans

**Goal:** providers can get paid-ready and publish plans. Covers FR-P4, FR-P5, FR-PL1 to FR-PL5, FR-PL10, FR-PM1, FR-PM3 to FR-PM5, FR-PM7, FR-PM9.

Tests first (Stripe fake at the client boundary):
- price is immutable once subscribers exist; changing creates a new price and archives the old
- only a Stripe-enabled, approved provider can publish a plan
- the same webhook event id is processed once
- bad webhook signature is rejected
- currency must be in the admin-enabled list

Tasks:
- [x] **T3.1** `StripeClient` wrapper with idempotency keys and a fake for tests (M).
- [x] **T3.2** Admin Stripe settings (keys, webhook secrets, mode indicator), encrypted (S).
- [x] **T3.3** Connect Express onboarding: create account, onboarding link, dashboard link, status sync (M).
- [x] **T3.4** Webhook endpoint (platform and Connect), signature check, `webhook_events` table, idempotent dispatch to handlers (M).
- [x] **T3.5** `account.updated` handler and provider Stripe status banner data (S).
- [x] **T3.6** `plans`, `prices` migrations; CRUD API; sync Product/Price to the connected account (L).
- [x] **T3.7** Trial days, features list, sort order, visibility, archive behavior (S).
- [x] **T3.8** Currency chosen from admin-enabled list; plan limits and price bounds from admin settings (S).
- [x] **T3.9** Stripe Tax toggle (platform then provider) (S).
- [x] **T3.10** Provider UI: Stripe connect status, plans manager (M).
- [x] **T3.11** Admin UI: plans listing across providers with force-archive; webhook event monitor with replay (FR-AD5, FR-AD11) (M).

**Exit criteria:** a provider completes Connect onboarding in Stripe test mode and creates a plan that exists on their connected account; replayed webhooks cause no duplicate effects. **Met against the fake gateway** (idempotent webhooks, replay, plan sync, price replacement all tested); **not yet run against real Stripe test mode** (needs keys, see Carried over).

---

## Phase 4: Storefront

**Goal:** a public branded page at `{slug}.platform.com`. Covers FR-S1 to FR-S5, FR-S7, FR-S9, FR-P7.

Tests first:
- rich text is sanitized (no scripts)
- draft content is not publicly visible; published is
- inactive or suspended provider shows the unavailable page
- media upload rejects wrong type and oversize files

Tasks:
- [ ] **T4.1** `storefront_pages`, `page_blocks`, `media` migrations and models (M).
- [ ] **T4.2** Public storefront API by tenant (page, plans, branding) with caching (M).
- [ ] **T4.3** Block editor API: reorder, show/hide, draft/publish, preview token (M).
- [ ] **T4.4** Media library: S3/MinIO upload, validation, image optimization (M).
- [ ] **T4.5** Sanitizer for rich text, SEO fields (title, description, OG image) (S).
- [ ] **T4.6** Storefront React app: hero, about, plans/pricing, FAQ, contact, footer; tenant resolution from host (L).
- [ ] **T4.7** Provider UI: content editor with live preview, publish (L).
- [ ] **T4.8** Unavailable page for inactive providers (S).
- [ ] **T4.9** Admin moderation: unpublish a storefront (FR-AD12) (S).

**Exit criteria:** a provider edits and publishes a page that renders on its subdomain, and an admin deactivation hides it.

---

## Phase 5: Checkout and subscriptions

**Goal:** customers can join a provider and pay. Covers FR-C1 to FR-C8, FR-C10, FR-PD1 to FR-PD4, FR-CP1 to FR-CP4.

Tests first:
- `provider_customers` is created only on subscribe or trial start
- a provider cannot list users who never subscribed
- signed-in customer gets one-click join without re-registering
- proration math (upgrade, downgrade) with integer money
- subscription status mirrors Stripe events, including replayed and out-of-order events
- cancel at period end and resume

Tasks:
- [ ] **T5.1** `subscriptions`, `invoices`, `payments`, `payment_methods` migrations and models (M).
- [ ] **T5.2** Provider-scoped Stripe customer creation on first join (S).
- [ ] **T5.3** Checkout API: SetupIntent/PaymentIntent, 3DS/SCA, create subscription with `application_fee_percent` placeholder (L).
- [ ] **T5.4** Webhook handlers: `customer.subscription.*`, `invoice.*`, `payment_intent.*`, refunds (L).
- [ ] **T5.5** Lifecycle: trial, active, cancel, resume, upgrade/downgrade with proration (L).
- [ ] **T5.6** Access endpoint for providers' products (`/subscriptions/{id}/access`) (S).
- [ ] **T5.7** Refunds (full and partial) by provider and admin (M).
- [ ] **T5.8** Storefront join flow UI: sign up, or "Continue as <name>" when signed in; Payment Element checkout (L).
- [ ] **T5.9** Customer portal UI: subscriptions, invoices, payment methods, profile (L).
- [ ] **T5.10** Provider dashboard: overview metrics, subscribers list (real users only), invoices and transactions (L).
- [ ] **T5.11** Admin: subscriptions and invoices listing with manual actions (FR-AD6) (M).
- [ ] **T5.12** Nightly reconciliation job against Stripe (FR-PM6) (M).

**Exit criteria:** end to end in Stripe test mode: join, pay, renew via test clock, upgrade, cancel; Playwright covers the customer and provider flows.

---

## Phase 6: Retry and dunning engine

**Goal:** failed payments are recovered or ended cleanly. Covers FR-R1 to FR-R9, FR-R12, FR-P9.

Tests first (Stripe test clocks and fakes):
- soft decline retries on the configured schedule (+1, +3, +5, +7 days)
- hard decline is never retried and asks for a new card at once
- authentication-required sends a 3DS link
- card update triggers an immediate retry
- final failure goes to grace, then cancel or pause per setting
- two workers running the same retry charge once
- provider deactivation pauses billing and reactivation resumes it

Tasks:
- [ ] **T6.1** `payment_attempts` and `dunning_events` migrations and models (S).
- [ ] **T6.2** Decline-code classifier (soft, hard, auth-required) with a table-driven test (M).
- [ ] **T6.3** Retry scheduler: daily command plus per-invoice queued job, locks, idempotency keys (L).
- [ ] **T6.4** Suppress Stripe automatic retries per invoice (`auto_advance=false` on `invoice.created` / `invoice.payment_failed`, per the Phase 0 spike) and verify with test clocks (S).
- [ ] **T6.5** State machine: past_due to grace to canceled/paused; admin-configurable schedule, grace days, final action (M).
- [ ] **T6.6** Dunning notifications wired to template keys (mail stub until Phase 7) (M).
- [ ] **T6.7** "Fix payment" flow reachable from emails; immediate retry after card update (M).
- [ ] **T6.8** Manual retry and mark-resolved for provider and admin (S).
- [ ] **T6.9** Provider deactivation pauses billing via `pause_collection`; reactivation resumes (FR-P9) (M).
- [ ] **T6.10** Dashboards: failed payments, recovery rate, at-risk MRR (M).
- [ ] **T6.11** Admin retry and dunning settings UI (S).

**Exit criteria:** a test-clock scenario walks failure, retries, recovery and final cancellation with the exact timestamps from the schedule.

---

## Phase 7: Email templates and mail config

**Goal:** all emails come from admin-editable templates. Covers FR-E1 to FR-E3, FR-E5, FR-E6, FR-AD8.

Tests first:
- variables render; unknown variable fails safely
- disabled template is not sent
- mail secrets are never returned by the API
- failed sends are logged and can be resent

Tasks:
- [ ] **T7.1** `email_templates` (+ versions) and `email_logs` migrations and models (M).
- [ ] **T7.2** Template renderer with a documented variable list per template key (M).
- [ ] **T7.3** Seed the default template set from PRD section 4.9 (auth, provider, billing, dunning, admin alerts) (M).
- [ ] **T7.4** Mailer using DB-stored credentials (SMTP/SES/Mailgun), encrypted; test-send endpoint (M).
- [ ] **T7.5** Queued sending, delivery log, resend (S).
- [ ] **T7.6** Admin UI: template list, editor with preview, version history, mail settings, delivery log (L).
- [ ] **T7.7** Replace Phase 1 and 6 mail stubs with real template keys (S).

**Exit criteria:** admin edits the "payment failed" template and the next dunning email reflects it; delivery log shows the send in Mailpit.

---

## Phase 8: Commission v1

**Goal:** platform fee configured, applied and visible. Covers FR-CM1 to FR-CM4.

Tests first:
- rate resolution by effective date (provider override beats global default)
- the stored rate snapshot does not change when the rate changes later
- refund adjusts the commission record
- fee applied as `application_fee_percent` on new subscriptions

Tasks:
- [ ] **T8.1** `commission_rules` (global and per provider, effective-from) and `commissions` (per payment, with snapshot) (M).
- [ ] **T8.2** Rate resolver service and application to subscriptions (M).
- [ ] **T8.3** Record commission on `invoice.paid`; adjust on refund and dispute (M).
- [ ] **T8.4** Admin UI: default rate, per-provider override, commission report (M).
- [ ] **T8.5** Provider UI: gross, fee and net on transactions and totals (S).

**Exit criteria:** a payment shows gross, fee and net consistently in Stripe, the database, and both UIs.

---

## Phase 9: Hardening and launch

**Goal:** safe to put in front of real users. Covers FR-AD13, FR-L2, FR-L3, section 7.

Tasks:
- [ ] **T9.1** Reports and CSV export: revenue, churn, failed payments, commission (M).
- [ ] **T9.2** Security review: authz sweep of every route, CORS, headers/CSP, rate limits, secrets handling, dependency audit (M).
- [ ] **T9.3** Performance: indexes, N+1 checks, cache storefront and settings (S).
- [ ] **T9.4** Observability: Sentry, health endpoint, alerts for webhook failures and queue backlog (S).
- [ ] **T9.5** Backups and restore drill (S).
- [ ] **T9.6** Full Playwright sweep (admin, provider, customer, storefront) with console and network error checks (M).
- [ ] **T9.7** Hosting decision, wildcard DNS/SSL, production compose or deploy pipeline; seed the first admin on the target environment as a separate step (M).
- [ ] **T9.8** Re-run the sweep against the real URL (CORS and env checks) (S).
- [ ] **T9.9** Docs: API (Scramble), runbook, onboarding guide (S).

**Exit criteria:** all PRD P0 requirements covered by passing tests; sweep passes on the deployed URL.

---

## Cross-cutting checklist (every phase)

- UUID keys and soft deletes on every new table; tenant-isolation test for every tenant table.
- Stripe calls only through the client wrapper, with idempotency keys.
- Audit log entries for admin and provider mutations.
- Update `docs/PRD.md` if a decision changes, and tick tasks here when done.
