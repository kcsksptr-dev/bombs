CMD ?= /bin/sh -c

## Run tests (optionally: make test filter=[name])
test:
	$(CMD) "php artisan test $(if $(filter),--filter=$(filter))"

## Fix PHP code style (Laravel Pint)
php-coding-style:
	$(CMD) "vendor/bin/pint"

## Check PHP code style (Laravel Pint)
test-static-cs:
	$(CMD) "vendor/bin/pint --test"
