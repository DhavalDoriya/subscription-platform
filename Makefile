.PHONY: up down test test-api test-web migrate fresh shell

up:        ; docker compose up -d --build
down:      ; docker compose down
test:      test-api test-web
test-api:  ; docker compose exec app ./vendor/bin/pest
test-web:  ; docker compose exec node npm test
migrate:   ; docker compose exec app php artisan migrate
fresh:     ; docker compose exec app php artisan migrate:fresh --seed
shell:     ; docker compose exec app bash
