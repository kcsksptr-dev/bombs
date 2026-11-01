CMD ?= /bin/sh -c

## Install PHP vendor packages
composer-install:
	$(CMD) "composer install --no-interaction"

## Security audit
composer-audit:
	$(CMD) "composer audit --abandoned=report --no-interaction"

## Update PHP vendor packages
composer-update:
	$(CMD) "composer update --no-interaction"

