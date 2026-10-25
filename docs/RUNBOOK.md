# Runbook

Written for whoever deploys and operates the platform. Commands run from the project root; `php artisan` runs inside the `app` container when using Docker.

## What runs

| Process | Command | Why |
|---|---|---|
| Web | php-fpm behind nginx | the API and the SPA shell, on the main host and every `*.platform.com` storefront host |
| Queue | `php artisan horizon` | emails, webhook-triggered work, payment retries |
| Scheduler | `php artisan schedule:work` (or cron `* * * * * php artisan schedule:run`) | everything in the table below |
| PostgreSQL 16, Redis 7 | | data, cache, queues |

### Scheduled jobs (`routes/console.php`)

| Job | When | What |
|---|---|---|
| `billing:reconcile` | daily 02:30 | fixes drift between our subscriptions and Stripe |
| `billing:retry-payments` | hourly | retries failed invoices on the +1/+3/+5/+7 day schedule |
| `billing:expire-grace` | hourly | cancels or pauses subscriptions whose grace period ended |
| `billing:send-reminders` | daily 09:00 | trial-ending and upcoming-renewal emails |
| `providers:remind-stripe-onboarding` | daily 09:15 | nudges providers who have not finished Stripe onboarding |
| `platform:check-alerts` | every 15 min | emails admins about failing webhooks and a high payment failure rate |

If the scheduler is not running, no payment is ever retried and grace never ends: monitor it.

## Environment

Copy `.env.example`. The values that matter in production:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://platform.com`, `APP_BASE_DOMAIN=platform.com`, `FRONTEND_URL` (same as `APP_URL`).
- `DB_*` for PostgreSQL, `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
- `JWT_SECRET` (`php artisan jwt:secret`), `APP_KEY`. Keep `APP_KEY` safe: mail credentials and the Stripe secrets in the settings table are encrypted with it.
- `REFRESH_COOKIE_DOMAIN=.platform.com` so a sign-in on the main host carries to storefronts.
- `STRIPE_ACCOUNT_TYPE=express` (use `standard` only while the platform Stripe account is registered in India; Stripe refuses Express there).
- `SECURITY_CSP` and `SECURITY_HSTS` default to on outside local development. `API_DOCS=true` opens `/docs/api` (staging only).
- `CORS_ALLOWED_ORIGINS` stays empty: the SPA and API share a host.
- Set `expose_php = Off` in `php.ini` so the PHP version is not advertised.
- Optional: `PUBLIC_RATE_LIMIT`, `AUTH_RATE_LIMIT` (per IP per minute).

Stripe keys, webhook secrets and mail credentials are **not** in `.env`: an admin saves them under Admin, Settings (stored encrypted).

## First deploy

1. Build and start the stack; run `php artisan migrate --force`.
2. `php artisan db:seed --class=RoleSeeder` (roles) and `--class=EmailTemplateSeeder` if the migration did not already seed them.
3. Create the first admin as a **separate step**, never part of the deploy: `php artisan app:create-admin you@example.com --name="Your Name" --super` (prompts for the password).
4. Point wildcard DNS (`platform.com` and `*.platform.com`) at the load balancer and install a wildcard certificate (DNS-01 challenge).
5. Sign in, open Settings, save the Stripe secret key, publishable key and both webhook secrets, then Settings, Mail and send a test email.
6. In the Stripe dashboard add two webhook endpoints (the Settings page shows both URLs): the platform endpoint `/api/v1/webhooks/stripe` and the Connect endpoint `/api/v1/webhooks/stripe/connect` (listen to events on connected accounts).
7. Check `GET /api/v1/health/ready` answers 200 and Horizon shows workers.

## Everyday operations

- **A webhook failed**: Admin, Webhooks, open the event, fix the cause, Replay. Processing is idempotent.
- **A customer's payment keeps failing**: Admin, Invoices, open the dunning history; Retry now, or Mark resolved if it was settled outside Stripe.
- **An email did not arrive**: Admin, Email log; failed ones can be resent. Mail settings can be tested from Settings.
- **Change a commission rate**: Admin, Commission rates, add a rate from a date. It applies to subscriptions sold from then on; running subscriptions and past payments keep their rate.
- **Pause a provider**: Admin, Providers, Deactivate or Suspend. Billing pauses and the storefront goes offline; reactivating resumes billing.
- **Reports and exports**: Admin, Reports (CSV for revenue, payments, commission, failures, subscribers). Providers have the same under Dashboard, Reports.

## Alerts

`platform:check-alerts` emails every admin when webhook events failed in the last hour, or at least 30% of the last hour's payments (and at least 10 payments) failed. Each alert is sent at most once an hour. New providers waiting for approval also email the admins. Horizon's dashboard (`/horizon`, admins only) shows queue length and failed jobs. Add an uptime monitor on `/api/v1/health/ready`. Error tracking is wired through Sentry: set `SENTRY_LARAVEL_DSN` (and `SENTRY_ENVIRONMENT`) to turn it on; with no DSN it does nothing, and personal data is not sent. Business errors (4xx `ApiException`) are not reported.

## Backups and restore

- `docker/ops/backup.sh` writes a compressed `pg_dump` to `BACKUP_DIR`, verifies it can be listed, optionally copies it to S3 (`BACKUP_S3_URI`) and prunes dumps older than `KEEP_DAYS` (14). Run it nightly from cron.
- `docker/ops/restore-drill.sh <dump>` restores into a scratch database and prints row counts. Run it once a month and compare with production.
- Back up `.env` and `APP_KEY` separately and securely: a database dump is useless for the encrypted settings without the key.
- Uploaded media lives on the `public` disk (local or S3). Back up the bucket, or enable bucket versioning.

## Releasing

1. `./vendor/bin/pest`, `npm test`, `npm run typecheck`, `npm run lint`, `npm run build`, `./vendor/bin/pint --test` must pass.
2. Deploy, then `php artisan migrate --force`, `php artisan horizon:terminate` (the supervisor restarts workers), `php artisan config:cache route:cache view:cache`.
3. Run the Playwright sweep against the real URL: `E2E_BASE_URL=https://platform.com npm run e2e` with a seeded admin. It checks every area for console and network errors, which catches CORS, CSP and cookie-domain mistakes.

## API reference

`docs/openapi.json` is the exported OpenAPI document (`php artisan scramble:export --path=docs/openapi.json`); regenerate it when routes change.
