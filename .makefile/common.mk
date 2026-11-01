filesystem-setup: filesystem-create filesystem-set-rights

filesystem-create:
	mkdir -p vendor node_modules storage/logs bootstrap/cache

## Set filesystem rights (php-fpm runs as www-data and must write storage/ and bootstrap/cache/)
filesystem-set-rights:
	chmod -R og+w storage bootstrap/cache

filesystem-clean:
	@sudo find storage ! -user $(shell id -u) -delete
	@sudo find bootstrap ! -user $(shell id -u) -delete
	rm -rf vendor node_modules public/build public/hot
