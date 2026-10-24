# Billing Platform

Multi-provider subscription billing (Laravel 13 + React/Vite + Stripe Connect). See [docs/PRD.md](docs/PRD.md) for requirements and delivery phases, and [CLAUDE.md](CLAUDE.md) for development conventions.

```sh
cp .env.example .env && make up
docker compose exec app composer install && php artisan key:generate && php artisan jwt:secret && php artisan migrate
```
