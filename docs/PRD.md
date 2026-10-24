# PRD: Multi-Provider Subscription Billing Platform

**Version:** 2.1 (replaces "Subscription Billing and Retry Engine" v1)
**Status:** Decisions locked (see section 10); Phase 0 in progress
**Date:** 2026-10-24

---

## 1. Overview

A multi-tenant SaaS platform where **Service Providers** (brands/companies) register, get their own storefront (subdomain), create subscription plans, and sell them to **Customers**. **Platform Admins** oversee everything. Payments run through **Stripe Connect**, with a built-in **payment retry (dunning) engine** and a **platform commission** model (config in v1, charging in a later phase).

### 1.1 Goals
- Let any brand self-onboard and start selling subscriptions in minutes.
- Give each provider a branded public page on `{slug}.platform.com`.
- Reliable recurring billing with intelligent retry and dunning.
- Full admin control: approvals, activation, configuration, email templates, commission.
- Architecture ready for commission/payout features without rework.

### 1.2 Non-Goals (v1)
- Usage-based / metered billing.
- Custom domains (CNAME) for providers (planned v2).
- Mobile apps.
- Multi-currency conversion (single currency per provider in v1).
- Tax automation beyond Stripe Tax toggle (optional).

---

## 2. Roles & Modules

### 2.1 Roles
| Role | Description |
|---|---|
| **Customer** | End user who buys/subscribes to a provider's plan. One global account, can subscribe to many providers. |
| **Provider Owner** | Registers the company, manages plans, page content, customers, payouts. |
| **Provider Staff** (v1.1) | Invited team members with limited permissions (e.g., support, content editor). |
| **Platform Admin** | Full platform control. |
| **Super Admin** | Admin + manage other admins, system config, secrets. |

### 2.2 Modules
1. **Auth & Identity** – registration, login, roles, password reset, email verification, 2FA (optional).
2. **Provider Onboarding & Tenancy** – provider registration, approval, subdomain, branding.
3. **Provider Storefront (CMS)** – public page builder/content.
4. **Plans & Pricing** – plans, prices, trials, coupons.
5. **Subscriptions & Checkout** – purchase, lifecycle, proration.
6. **Payments (Stripe Connect)** – onboarding, charges, webhooks.
7. **Retry & Dunning Engine** – failed payment recovery.
8. **Commission & Payouts** – platform fee rules (config now, full ledger later).
9. **Notifications & Email Templates** – template manager, delivery log.
10. **Admin Panel** – listings, moderation, configs, reports.
11. **Provider Dashboard** – subscribers, revenue, invoices.
12. **Customer Portal** – subscriptions, invoices, payment methods.
13. **Audit, Logs & Reporting**.

---

## 3. Technical Architecture

### 3.1 Stack
| Layer | Choice |
|---|---|
| Backend | Laravel 13 (REST JSON API only; one Blade view hosts the SPA shell) |
| Frontend | React 18/19 + TypeScript, **Vite**, living in `resources/js` (separate SPA, **no Inertia**) |
| Routing (FE) | React Router; TanStack Query for server state; Zustand/Context for UI state |
| UI | Tailwind CSS + shadcn/ui; admin panel follows the `build-admin-panel` skill (`.claude/skills/build-admin-panel`) |
| DB | PostgreSQL 16 |
| Cache/Queue/Session | Redis |
| Queue worker | Laravel Horizon |
| Payments | Stripe Connect (**direct charges**) + Stripe PHP SDK. Cashier is **not** used for core billing; Connect + platform fees need direct API control |
| Mail | SMTP/SES/Mailgun via Laravel Mail, templates stored in DB |
| Search (optional) | Laravel Scout + Meilisearch for listings |
| Storage | S3-compatible (MinIO locally) |
| Testing | Pest/PHPUnit, Vitest + Testing Library, Playwright (E2E), Stripe test clocks |

### 3.2 Frontend/Backend Setup
- Single repo. Laravel serves API under `/api/v1`. Vite builds the React SPA from `resources/js` (entry `resources/js/main.tsx`), with `laravel-vite-plugin`.
- Three SPA "areas" sharing one codebase, split by route + role guard:
  - `/admin/*` – platform admin
  - `/dashboard/*` – provider panel
  - `/account/*` – customer portal
  - Public storefront on subdomain (`{slug}.platform.com`) served by the same SPA, resolving the tenant from the host.
- Dev: Vite dev server with HMR proxied to Laravel. Prod: built assets served by Nginx; Laravel catch-all returns the SPA shell for non-API routes.

### 3.3 Docker
Compose services:
- `app` (php-fpm 8.3), `nginx`, `node` (Vite dev), `mysql`/`postgres`, `redis`, `horizon` (queue worker), `scheduler` (`schedule:work`), `mailpit` (local mail), `minio` (S3), `stripe-cli` (webhook forwarding).
- Wildcard local domain support (`*.platform.test`) via Nginx + `/etc/hosts` or dnsmasq/Traefik.
- `.env.example` for all services; Makefile shortcuts (`make up`, `make test`, `make migrate`).

### 3.4 Authentication: JWT + Refresh Tokens
Decision: **JWT access token + rotating refresh token** (`php-open-source-saver/jwt-auth`; Sanctum and Passport are not used).

- **Access token:** JWT, 15 min TTL, kept in memory by the SPA, sent as `Authorization: Bearer`. Claims: `sub` (user uuid), `roles`, `jti`.
- **Refresh token:** opaque random value, 30 day TTL, stored **hashed** in `refresh_tokens` (uuid id, user_id, family_id, user agent, ip, expires_at, revoked_at, replaced_by). Delivered as an `httpOnly; Secure; SameSite=Lax` cookie scoped to `/api/v1/auth` with `Domain=.platform.com`, so it works on every provider subdomain.
- **Rotation + reuse detection:** every `/auth/refresh` issues a new refresh token and revokes the old one. Presenting an already-used token revokes the whole token family (forces re-login).
- **Logout:** revokes the refresh token and blacklists the access token `jti` (Redis, until expiry). "Log out everywhere" revokes all of the user's families.
- **SPA behavior:** an Axios interceptor refreshes once on 401 and queues concurrent requests; on refresh failure it redirects to login.
- **Authorization:** roles + permissions via `spatie/laravel-permission` (uuid models); policies per resource; tenant scoping enforced globally.
- **Security notes:** access token never in localStorage; CORS allow-list for `*.platform.com`; login and refresh rate-limited; password change revokes all families.

### 3.5 Multi-Tenancy
- **Single database, shared schema**, every tenant-owned table has `provider_id`; enforced with a global Eloquent scope + policies (simplest, scales well; avoids per-tenant DB overhead).
- Tenant resolved from subdomain by middleware (`slug` → provider); API requests from a storefront carry tenant context.
- Reserved subdomains blocked (`www`, `admin`, `api`, `app`, `mail`, etc.).
- Customers are **global users**; relationship to a provider is a `provider_customers` record (so one login, many providers). Provider staff/owners are separate role assignments scoped to a provider.
- **Storefront join flow:** a visitor on a provider storefront who is not signed in sees sign up / sign in. A customer already signed in to the platform sees **"Continue as <name>"**, a one-click join with no new registration. The `provider_customers` link (and the provider-scoped Stripe customer) is created only when they subscribe or start a trial, never by merely visiting.
- **Provider sees real users only:** provider dashboards and APIs list only customers with a `provider_customers` record (people who actually subscribed or started checkout with that provider). The global user table is never exposed to providers, and PII shown is limited to what the provider needs to serve the customer.

### 3.6 Data Conventions (every table)
- **Every primary and foreign key is a UUID** (Eloquent `HasUuids`; `uuid`/`foreignUuid` columns). No auto-increment ids in the domain schema. Only the framework-internal `jobs` and `job_batches` queue tables are exempt.
- **Every business table uses soft deletes** (`deleted_at`). Financial records (invoices, payments, attempts, refunds, commissions) are never hard-deleted. The audit/activity log is append-only (no update, no soft delete).
- Money = integer minor units + ISO currency code. Timestamps UTC.
- `make:model` and `make:migration` stubs are customised so new code follows these conventions by default.

### 3.7 Engineering Method: TDD
- **Red, green, refactor.** No production code without a failing test first; each phase starts by writing the acceptance tests listed for it.
- **Backend:** Pest (feature tests through HTTP, unit tests for money, proration and retry rules). Stripe is faked at the client boundary in unit tests; Stripe test mode and test clocks for integration tests.
- **Frontend:** Vitest + Testing Library, MSW for API mocking.
- **E2E:** Playwright, following the `build-admin-panel` skill: real login, every sidebar page, one real write, logout; fail on console errors or failed requests.
- **Definition of done:** tests written first and passing; `pest`, `npm run typecheck`, `npm test`, `npm run build` green; CI green; requirement ids (e.g. FR-R3) appear in test names.
- Tenant-isolation and convention tests (uuid ids, soft deletes) are permanent parts of the suite.



---

## 4. Functional Requirements

Priority: **P0** = MVP, **P1** = fast follow, **P2** = later.

### 4.1 Auth & Identity
- FR-A1 (P0) Register/login/logout for customers, providers, admins.
- FR-A2 (P0) Email verification required before purchase / publishing.
- FR-A3 (P0) Password reset via emailed signed link; rate-limited.
- FR-A4 (P0) Role-based access (customer, provider owner, admin, super admin).
- FR-A5 (P0) Login throttling, account lockout after repeated failures.
- FR-A6 (P1) 2FA (TOTP) for providers and admins; mandatory for admins.
- FR-A7 (P1) Social login (Google).
- FR-A8 (P0) Profile management: name, email, password, avatar.
- FR-A9 (P1) Active session list + "log out other devices".
- FR-A10 (P0) Admin can impersonate a user/provider (audited, with banner and exit).

### 4.2 Provider Onboarding
- FR-P1 (P0) Registration form: company name, owner details, desired subdomain/slug (live availability check), business category, country, support email.
- FR-P2 (P0) Provider status lifecycle: `pending → approved → active ↔ inactive/suspended`, plus `rejected`. Admin setting `provider.auto_approve` (**default: on** for now, can be switched to manual at any time).
- FR-P9 (P0) **Deactivation pauses billing:** when a provider becomes inactive/suspended, active subscriptions are paused (Stripe `pause_collection`), the storefront shows unavailable, new checkouts are blocked; reactivation resumes collection. Customers are notified.
- FR-P3 (P0) Slug rules: lowercase alphanumeric + hyphen, 3–40 chars, unique, reserved-word blacklist, immutable after approval (change via admin only).
- FR-P4 (P0) Stripe Connect onboarding (Express accounts): provider must complete before accepting payments; show status (`not started / pending / restricted / enabled`).
- FR-P5 (P0) Provider can only publish storefront and plans when approved **and** Stripe-enabled.
- FR-P6 (P1) Staff invitations with roles (admin/manager/support/content).
- FR-P7 (P0) Provider settings: branding (logo, favicon, colors), support contact, timezone, currency, legal links (terms, privacy, refund policy).
- FR-P8 (P2) Custom domain mapping with SSL.

### 4.3 Provider Storefront (Page Content)
- FR-S1 (P0) Public site at `{slug}.platform.com` with: hero, about, plans/pricing section, FAQ, contact, footer.
- FR-S2 (P0) Content editor: section-based blocks (text, image, feature list, testimonials, FAQ, CTA); reorder, show/hide.
- FR-S3 (P0) Draft / published states; preview before publish.
- FR-S4 (P0) SEO fields per page: title, meta description, OG image.
- FR-S5 (P0) Media library with upload limits (type/size) and image optimization.
- FR-S6 (P1) Additional custom pages (e.g., About, Blog-lite), simple nav menu builder.
- FR-S7 (P0) Rich text sanitized server-side (XSS safe); no arbitrary script injection.
- FR-S8 (P1) Theme presets (a few templates) + color/typography customization.
- FR-S9 (P0) Storefront hidden/returns "unavailable" page when provider is inactive/suspended.

### 4.4 Plans & Pricing
- FR-PL1 (P0) Provider CRUD plans: name, description, features list, status (draft/active/archived), sort order, visibility.
- FR-PL2 (P0) Each plan has one or more **prices**: amount, currency, interval (day/week/month/year), interval count.
- FR-PL3 (P0) Trial period (days) per plan.
- FR-PL4 (P0) Plans/prices sync to Stripe Product/Price **on the provider's connected account**. Prices are immutable once subscribers exist (create new price, archive old).
- FR-PL5 (P0) Archiving a plan stops new signups; existing subscribers continue.
- FR-PL6 (P1) Coupons / promo codes (percent/fixed, duration once/repeating/forever, max redemptions, expiry).
- FR-PL7 (P1) Setup fee (one-time) on a plan.
- FR-PL8 (P1) Plan limits metadata (e.g., seats, features) for provider's own use.
- FR-PL9 (P2) Add-ons, multiple plans per subscription, per-seat pricing.
- FR-PL10 (P0) Admin-configurable limits: max plans per provider, min/max price, allowed intervals, and the **list of enabled currencies**. Each provider chooses their currency from the admin-enabled list (changeable only before first paid subscription).

### 4.5 Checkout & Subscriptions
- FR-C1 (P0) Customer registers/logs in on storefront, selects plan, pays via Stripe (Stripe Elements / Payment Element with SetupIntent/PaymentIntent, SCA/3DS supported).
- FR-C2 (P0) Subscription statuses: `trialing, active, past_due, grace, paused, canceled, expired, incomplete`.
- FR-C3 (P0) Upgrade/downgrade with proration (configurable: immediate vs next cycle).
- FR-C4 (P0) Cancel: at period end (default) or immediately; resume before period end.
- FR-C5 (P1) Pause/resume subscription.
- FR-C6 (P0) Invoices generated for each billing cycle; PDF/hosted invoice link; email receipt.
- FR-C7 (P0) Customer manages payment methods (add, set default, remove); update card to fix failed payment.
- FR-C8 (P0) Access state exposed via API (`GET /subscriptions/{id}/access`) so providers' products can gate access based on status.
- FR-C9 (P1) Outbound webhooks to providers (subscription.created/updated/canceled, invoice.paid/failed) with signing secret and retry.
- FR-C10 (P0) Refunds (full/partial) by provider (and admin), logged.
- FR-C11 (P1) Customer data export + account deletion (GDPR flows).

### 4.6 Payments & Webhooks (Stripe)
- FR-PM1 (P0) Stripe Connect Express onboarding + dashboard login link for providers.
- FR-PM2 (P0) Use **direct charges**: Stripe customers, subscriptions and invoices live on the provider's connected account (`Stripe-Account` header); the provider is merchant of record. Commission is taken with `application_fee_percent` on the subscription.
- FR-PM9 (P0) Stripe Tax can be enabled (platform toggle, then per provider; provider must complete tax setup on their connected account).
- FR-PM3 (P0) Single webhook endpoint for platform + Connect events; signature verified.
- FR-PM4 (P0) Idempotent processing: store `webhook_events` (event id unique); safe replays; ordered handling by object version/timestamp.
- FR-PM5 (P0) Handled events (minimum): `checkout/payment_intent.*`, `invoice.created/paid/payment_failed/finalized`, `customer.subscription.created/updated/deleted`, `charge.refunded/dispute.*`, `account.updated`, `payment_method.*`.
- FR-PM6 (P0) Local DB is a synced mirror of Stripe state; Stripe is source of truth; nightly reconciliation job detects drift.
- FR-PM7 (P0) Stripe API calls use idempotency keys.
- FR-PM8 (P1) Dispute handling: notify provider, flag subscription, track evidence deadline.

### 4.7 Retry & Dunning Engine
- FR-R1 (P0) Stripe's automatic retry is **disabled**; platform engine owns schedule (decision confirmable in §10).
- FR-R2 (P0) Default schedule (admin-configurable, optionally overridable per provider within admin bounds): retry at +1, +3, +5, +7 days after first failure.
- FR-R3 (P0) Decline classification:
  - **Soft** (insufficient funds, processing error, generic decline) → retry.
  - **Hard** (stolen/lost card, do_not_honor permanent, invalid account) → no retry, request new payment method immediately.
  - **Authentication required** → notify customer with link to complete 3DS.
- FR-R4 (P0) Each attempt recorded in `payment_attempts` (attempt #, decline code, result, timestamp, next retry).
- FR-R5 (P0) On final failure: subscription → `grace` for N days (configurable), then `canceled`/`expired` (configurable action: cancel vs pause).
- FR-R6 (P0) Dunning email sequence tied to attempts (pre-failure warning optional, failure notice, retry reminders, final notice, cancellation notice, recovered notice). Uses editable templates (§4.9).
- FR-R7 (P0) Retries run via scheduled job + queued per-invoice jobs; safe against double execution (locks + idempotency).
- FR-R8 (P0) Card updated by customer → immediate retry of open invoice.
- FR-R9 (P0) Manual retry button for provider/admin; manual "mark resolved".
- FR-R10 (P1) Smart timing (avoid weekends/paydays, retry in customer timezone morning).
- FR-R11 (P1) Card updater / expiring-card pre-notice (30/7 days before expiry).
- FR-R12 (P0) Dashboards: failed payments, recovery rate, at-risk MRR.

### 4.8 Commission & Payouts (Phased)
**v1 (P0 – foundation):**
- FR-CM1 Global default commission: percent and/or fixed per transaction (admin setting).
- FR-CM2 Per-provider override (admin) and effective-from dates (history kept).
- FR-CM3 Commission calculated and stored per payment (`commission_amount`, `rate_snapshot`) and applied as Stripe `application_fee`.
- FR-CM4 Admin and provider can view commission per transaction and totals.

**Later (P1/P2):**
- FR-CM5 Tiered commission (by volume/plan), per-plan commission, promotional free periods.
- FR-CM6 Commission ledger (double-entry style): earned, refunded, reversed on refund/dispute.
- FR-CM7 Payout reports, statements (CSV/PDF) per provider, payout schedule visibility (Stripe handles transfers).
- FR-CM8 Platform subscription fee for providers (SaaS plans for providers) and invoices to providers.
- FR-CM9 Tax handling on platform fee.

### 4.9 Email Templates & Notifications
- FR-E1 (P0) Admin-managed **global templates** stored in DB: key, name, subject, HTML body, text body, enabled flag, version history.
- FR-E2 (P0) Variable placeholders (`{{customer.name}}`, `{{plan.name}}`, `{{amount}}`, `{{retry_date}}`, `{{update_payment_url}}`, `{{provider.name}}` etc.) with a documented variable list per template and live preview/test-send.
- FR-E3 (P0) Template set (minimum):
  - Auth: welcome, verify email, password reset, password changed, 2FA enabled.
  - Provider: registration received, approved, rejected, suspended/reactivated, Stripe onboarding reminder, new subscriber, plan canceled by customer.
  - Customer billing: subscription started, trial ending, receipt/invoice paid, upcoming renewal, subscription canceled, resumed, plan changed, refund issued.
  - Dunning: payment failed, retry reminder, action required (3DS), final notice, subscription suspended/canceled, payment recovered, card expiring.
  - Admin alerts: new provider pending approval, webhook failures, high failure rate.
- FR-E4 (P1) Provider-level template overrides (branding, from-name, reply-to) within admin-allowed fields.
- FR-E5 (P0) Mail credentials/config managed by admin: driver (SMTP/SES/Mailgun), host, port, username, password, encryption, from name/address; **secrets encrypted at rest**; "send test email" button.
- FR-E6 (P0) All emails queued; delivery log (status, error, timestamps); resend failed.
- FR-E7 (P1) In-app notifications (bell) for provider/admin; per-user notification preferences.
- FR-E8 (P1) Localization-ready templates.

### 4.10 Admin Panel
- FR-AD1 (P0) Dashboard: total providers (by status), customers, active subscriptions, MRR, GMV, failed payments, recovery rate, commission earned, recent signups.
- FR-AD2 (P0) **Providers listing**: search, filter (status, Stripe status, category, date), sort, pagination, export CSV; detail page with plans, subscribers, revenue, logs.
- FR-AD3 (P0) **Customers listing**: search, filter, view subscriptions across providers, block/unblock, impersonate.
- FR-AD4 (P0) **Provider actions**: approve, reject (with reason), activate, deactivate, suspend, edit slug, set commission override, set limits.
- FR-AD5 (P0) **Plan listing** across providers with force-archive/disable.
- FR-AD6 (P0) **Subscriptions & invoices listing** with filters and manual actions (retry, cancel, refund).
- FR-AD7 (P0) **System configuration** (grouped settings, validated, cached):
  - General: platform name, base domain, logo, support email, timezone, default currency.
  - Provider rules: auto-approve toggle, reserved slugs, max plans, allowed currencies/intervals.
  - Stripe: keys (encrypted), webhook secret, Connect settings, test/live mode indicator.
  - Retry/dunning defaults: schedule, grace days, final action.
  - Commission: defaults and overrides.
  - Mail: credentials (see FR-E5).
  - Security: password policy, 2FA enforcement, session lifetime.
- FR-AD8 (P0) Email template manager (FR-E1–E6).
- FR-AD9 (P0) Admin user management: create admins, assign roles/permissions (super admin only).
- FR-AD10 (P0) Audit log viewer (who/what/when/before-after) with filters.
- FR-AD11 (P0) Webhook event monitor: view, filter, replay failed events.
- FR-AD12 (P1) Content moderation: flag/unpublish storefronts violating terms.
- FR-AD13 (P1) Reports: revenue, churn, retention, failed-payment analysis, commission; CSV export.
- FR-AD14 (P1) Announcements/banners to providers.

### 4.11 Provider Dashboard
- FR-PD1 (P0) Overview: MRR, active subscribers, new/churned, failed payments, upcoming renewals.
- FR-PD2 (P0) Plans manager (§4.4), storefront editor (§4.3), settings/branding.
- FR-PD3 (P0) Subscribers list: filter by plan/status, subscriber detail (history, invoices, attempts), cancel/refund/retry actions.
- FR-PD4 (P0) Invoices and transactions with commission shown (gross, fee, net).
- FR-PD5 (P0) Stripe Connect status banner + link to Stripe Express dashboard.
- FR-PD6 (P1) Coupons, staff, webhook endpoints/API keys, export CSV.
- FR-PD7 (P1) Activity log for their own account.

### 4.12 Customer Portal
- FR-CP1 (P0) My subscriptions (status, next billing, change/cancel/resume).
- FR-CP2 (P0) Billing history and invoice downloads.
- FR-CP3 (P0) Payment methods management; "fix payment" flow reachable from dunning emails.
- FR-CP4 (P0) Profile and password.
- FR-CP5 (P1) Data export / delete account.

### 4.13 Audit & Logging
- FR-L1 (P0) Audit trail for admin/provider mutations (spatie/activitylog or custom).
- FR-L2 (P0) Structured app logs, error tracking (Sentry), request IDs.
- FR-L3 (P0) Financial records are never hard-deleted (soft-delete/immutable).

---

## 5. Data Model (High-Level)

`users` · `roles/permissions` · `providers` (slug, status, stripe_account_id, settings, commission_override) · `provider_users` (staff + role) · `provider_customers` (provider_id, user_id, stripe_customer_id) · `storefront_pages` / `page_blocks` · `media` · `plans` · `prices` (stripe ids) · `coupons` · `subscriptions` · `subscription_items` · `invoices` · `invoice_lines` · `payments` · `payment_methods` · `payment_attempts` · `dunning_events` · `refunds` · `commission_rules` · `commissions` (per payment) · `email_templates` (+ versions) · `email_logs` · `settings` (key/value, encrypted flag) · `webhook_events` · `outbound_webhooks` · `audit_logs` · `notifications`.

Rules: money stored as integer minor units + currency; all timestamps UTC; Stripe IDs indexed & unique; soft deletes on financial/business entities.

---

## 6. Key Flows

1. **Provider onboarding:** register → verify email → admin approval (or auto) → Stripe Connect onboarding → create plans → publish storefront → live at `{slug}.platform.com`.
2. **Customer purchase:** visit storefront → sign up/login → choose plan → Payment Element (3DS if needed) → subscription active → receipt email → provider notified.
3. **Renewal & failure:** invoice created → payment fails → attempt logged → classify decline → email → scheduled retries → success (recovered) **or** final failure → grace → cancel/suspend → notifications.
4. **Commission:** payment succeeds → fee computed from effective rule → applied as application fee → stored on `commissions` → shown to provider/admin.
5. **Admin deactivation:** admin sets provider inactive → storefront unavailable, new checkouts blocked, existing subscriptions **paused** (collection paused), emails sent; reactivation resumes billing.

---

## 7. Non-Functional Requirements

- **Security:** OWASP top 10 hardening; CSRF (Sanctum), strict CORS, rate limits, input validation via Form Requests, output sanitization, encrypted secrets (`Crypt`/encrypted casts), no card data touches our servers (PCI SAQ-A via Stripe Elements), HTTPS only, security headers/CSP.
- **Identifiers:** UUID everywhere; soft deletes on business tables (section 3.6).
- **Tenant isolation:** global scopes + automated tests asserting no cross-tenant data leaks.
- **Performance:** API p95 < 300ms on listings; paginated and indexed queries; cached settings and storefront content.
- **Reliability:** webhook handlers idempotent; queued jobs with retries/backoff and failed-jobs table; scheduler single-instance locking.
- **Scalability:** stateless app containers, Redis for sessions/cache/queues, horizontal worker scaling.
- **Observability:** Horizon, Sentry, health endpoint, alerts on webhook failure rate and queue backlog.
- **Backups:** daily DB backups, tested restore.
- **Compliance:** GDPR basics (export/delete, consent, privacy policy), Stripe/PCI responsibilities documented.
- **Accessibility/UX:** responsive, WCAG AA basics; loading, empty, and error states everywhere.
- **Testing:** unit + feature tests for billing logic, Stripe test clocks for renewal/retry simulations, E2E for core flows, CI pipeline (lint, tests, build) on each PR.

---

## 8. API Conventions

- Versioned `/api/v1`, JSON, Laravel API Resources for responses, consistent error envelope, pagination (cursor or page), filtering/sorting params, OpenAPI docs (Scribe or Scramble).
- Separate route groups: `/public` (storefront), `/customer`, `/provider`, `/admin`.

---

## 9. Delivery Phases (TDD)

Every phase: write the listed acceptance tests first (red), implement (green), refactor, run the full suite, verify in a real browser where UI exists (per `build-admin-panel` skill), then move on. Each phase ends with a demoable, deployable state.

### Phase 0: Foundation  *(in progress)*
Scope: repo skeleton, Docker stack (php-fpm, nginx wildcard, node/Vite, Postgres, Redis, Horizon, scheduler, Mailpit, MinIO, Stripe CLI), Laravel 13 + React/TS/Vite in `resources/js`, Pest + Vitest + Playwright, CI, UUID + soft-delete conventions (custom stubs, base models), project instructions (`CLAUDE.md`), custom Role/Permission/Activity models.
Acceptance tests: SPA shell served for non-API routes; users use UUID keys; soft delete keeps rows; React shell renders; CI green.
Open spikes: confirm Stripe Smart Retries can be disabled for connected accounts, or define the fallback (engine pays invoices; see Phase 6).

### Phase 1: Auth (JWT + refresh) and RBAC
Scope: register, login, logout, refresh rotation with reuse detection, email verification, password reset, rate limits, roles/permissions seeding, first admin seed command, frontend auth store + Axios refresh interceptor + route guards.
Tests first: login returns access token + refresh cookie; expired access token refreshes; reused refresh token revokes the family; logout blacklists `jti`; role guards block cross-role access; throttling.

### Phase 2: Tenancy, providers and admin core
Scope: provider registration (slug rules, reserved words, availability check), auto-approve setting (default on), provider lifecycle and admin actions, tenant resolution from subdomain, global tenant scope, admin settings store (encrypted secrets), audit log, admin shell (sidebar, generic table/dialog components), admin dashboard, providers and customers listings with active/inactive control.
Tests first: slug validation; tenant scope isolation (A cannot read B); status transitions; admin-only endpoints; settings encryption; listing filters/pagination envelope.

### Phase 3: Stripe Connect and plans
Scope: Connect Express onboarding, `account.updated` sync, plans/prices CRUD synced to the connected account, currency chosen from admin-enabled list, plan limits, Stripe Tax toggle, webhook endpoint with signature check and idempotent `webhook_events`.
Tests first: Stripe client faked; price immutability once subscribed; duplicate webhook processed once; only Stripe-enabled providers can publish plans.

### Phase 4: Storefront
Scope: subdomain-resolved public page, block-based content editor, draft/publish, SEO fields, media library, theme basics, unavailable page for inactive providers.
Tests first: HTML sanitisation; draft not publicly visible; inactive provider shows unavailable; media type and size limits.

### Phase 5: Checkout and subscriptions
Scope: storefront join flow ("Continue as <name>" or sign up), Payment Element checkout (3DS), subscription lifecycle (trial, active, cancel, resume, upgrade/downgrade with proration), invoices, receipts, customer portal, provider subscribers list showing real users only, refunds.
Tests first: provider-customer link created only on subscribe; provider cannot see non-subscribed users; proration math; status mapping from webhook events; state mirrors Stripe after replayed events.

### Phase 6: Retry and dunning engine
Scope: own retry schedule (default +1, +3, +5, +7 days, admin-configurable), decline classification (soft, hard, auth-required), `payment_attempts`, grace period then cancel/pause, update-card triggers immediate retry, manual retry, locks for double-execution, deactivation pause/resume (FR-P9), failed-payment dashboards.
Tests first (Stripe test clocks + fakes): soft decline retries on schedule; hard decline never retries; recovery after card update; final failure moves to grace then cancelled; concurrent job runs charge once.

### Phase 7: Email templates and mail config
Scope: DB-backed templates with variables, preview and test-send, version history, template set from section 4.9, admin mail credentials (encrypted) with test mail, delivery log with resend.
Tests first: variable rendering; missing variable handling; disabled template not sent; secrets never returned by the API.

### Phase 8: Commission v1
Scope: global default, per-provider override with effective dates, `application_fee_percent` applied on subscriptions, per-payment commission snapshot, admin and provider views and totals.
Tests first: rate resolution by effective date; snapshot unchanged when the rate changes later; refund adjusts commission record.

### Phase 9: Hardening and launch
Scope: reports and CSV export, security review, performance indexes, backups, Playwright full sweep against a deployed URL (CORS, seeded admin), docs. Hosting decision is deferred until here.

### Later
Commission ledger and tiers, custom domains, coupons+, provider staff roles, outbound webhooks, 2FA, social login, i18n.

---

## 10. Decisions

| # | Decision | Status |
|---|---|---|
| 1 | Stripe **direct charges** (provider is merchant of record), platform fee via `application_fee_percent` | Locked |
| 2 | Own retry engine (Stripe automatic retries disabled) | Locked, pending Phase 0 spike on connected accounts |
| 3 | Global customer account; storefront shows sign up for new users and one-click "Continue as" for signed-in users; provider sees only users who actually subscribed | Locked |
| 4 | Provider auto-approve is **on by default**, configurable in admin settings | Locked |
| 5 | Provider picks currency from the list the admin enables | Locked |
| 6 | Provider deactivation **pauses billing** for existing subscribers | Locked |
| 7 | Stripe Tax enabled in v1 (toggle) | Locked |
| 8 | Hosting target | On hold (decide in Phase 9) |
| 9 | Auth: **JWT access token + rotating refresh token** | Locked |
| 10 | UI: Tailwind + shadcn/ui; admin built with the `build-admin-panel` skill | Locked |
| 11 | UUID for every id; soft deletes on business tables | Locked |
| 12 | Laravel 13, PostgreSQL 16, TDD with Pest/Vitest/Playwright | Locked |

---

## 11. Success Metrics

- Provider time-to-first-plan < 15 minutes.
- Payment recovery rate ≥ 40% of initially failed invoices.
- Webhook processing success ≥ 99.9%, zero duplicate charges.
- Zero cross-tenant data exposure incidents.
- Checkout conversion rate and involuntary churn tracked from launch.
