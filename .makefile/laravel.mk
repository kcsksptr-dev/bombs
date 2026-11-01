CMD ?= /bin/sh -c

laravel-setup: laravel-migrate laravel-optimize-clear

## Publish Laravel vendors
laravel-vendor-publish:
	$(CMD) "php artisan vendor:publish --all"

## Run Laravel database migration
laravel-migrate:
	$(CMD) "php artisan migrate --force"

## Run Laravel database migration rollback
laravel-migrate-rollback:
	$(CMD) "php artisan migrate:rollback"

## Run Laravel database seeder
laravel-db-seed:
	$(CMD) "php artisan db:seed"

## Clear Laravel cache
laravel-cache-clear:
	$(CMD) "php artisan cache:clear"

## List available Laravel commands
laravel-list:
	$(CMD) "php artisan list"

## Clear Laravel optimization caches
laravel-optimize-clear:
	$(CMD) "php artisan optimize:clear"
