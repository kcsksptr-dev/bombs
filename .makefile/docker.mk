DOCKER_HOST_IP = $(shell docker network inspect bridge -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}')
# Address of this machine on the local network; phones use it to reach the game
LAN_IP ?= $(shell ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($$i == "src") print $$(i + 1)}')

define set_docker_command
	$(eval APP_ENV=$(2))
	$(eval include .environment/.env.docker-compose)
	$(eval DOCKER_COMPOSE=APP_ENV=$(2) DOCKER_HOST_IP=${DOCKER_HOST_IP} LAN_IP=${LAN_IP} HOST_UID=$(shell id -u) HOST_GID=$(shell id -g) docker compose --env-file .environment/.env.docker-compose -f ${DOCKER_COMPOSE_FILE})
	$(eval CMD=$(DOCKER_COMPOSE) exec -u $(1) cli /bin/sh -c)
	$(eval CMDTI=$(DOCKER_COMPOSE) exec -ti -u $(1) cli /bin/sh -c)
	$(eval DOCKER_COMPOSE_PUBLIC=$(DOCKER_COMPOSE) -f $(PROJECT_DIRECTORY)/.docker/compose/docker-compose.public.yml)
endef

$(eval $(call set_docker_command,www-data,local))

## Build Docker images
build:
	$(DOCKER_COMPOSE) build --pull

## Start Docker containers
start:
	$(DOCKER_COMPOSE) up -d

## Restart Docker containers (e.g. after changing .environment/.env.local)
restart:
	$(DOCKER_COMPOSE) up -d --force-recreate

## Show Docker containers logs
log:
	$(DOCKER_COMPOSE) logs -f

## Show Docker containers status
status:
	$(DOCKER_COMPOSE) ps

## Stop Docker containers
stop:
	$(DOCKER_COMPOSE) down

## Run interactive PHP container CLI
cli:
	$(DOCKER_COMPOSE) exec -ti -u www-data cli /bin/sh

## Print the URLs the game is reachable on
urls:
	echo "This machine:   http://$(APP_SLUG).$(SERVER_DOMAIN)"
	echo "Phones (LAN):   http://$(LAN_IP)"
	echo "Phones (name):  http://$(APP_SLUG).$(LAN_IP).nip.io"

# "public" is also a folder in a Laravel app, so these must be phony or make skips them as "up to date"
.PHONY: public public-url public-log public-reload public-stop

# Where the app reads the current public address from (see App\Support\PublicUrl)
PUBLIC_URL_FILE = storage/app/public_url

## Put the game online through a Cloudflare quick tunnel and print the public link
public:
	rm -f public/hot $(PUBLIC_URL_FILE)
	$(CMD) "npm run build" > /dev/null
	$(DOCKER_COMPOSE_PUBLIC) up -d --remove-orphans=false
	echo "Waiting for the tunnel..."
	for i in $$(seq 1 30); do \
		url=$$(docker logs $(APP_SLUG)_$(APP_ENV)_tunnel 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1); \
		if [ -n "$$url" ]; then echo "$$url" > $(PUBLIC_URL_FILE); break; fi; \
		sleep 1; \
	done; \
	if [ -z "$$url" ]; then echo "No tunnel address yet; check: make public-log"; exit 1; fi; \
	echo ""; echo "  Online: $$url"; echo ""

## Show the current public link
public-url:
	cat $(PUBLIC_URL_FILE) 2>/dev/null || echo "Not online (run: make public)"

## Show the tunnel logs
public-log:
	docker logs -f $(APP_SLUG)_$(APP_ENV)_tunnel

## Reload .environment/.env.local into the PHP containers while the tunnel stays up
public-reload:
	$(DOCKER_COMPOSE_PUBLIC) up -d --force-recreate --no-deps cli frontend worker scheduler

## Take the game offline again (local network only, debug output back on)
public-stop:
	rm -f $(PUBLIC_URL_FILE)
	$(DOCKER_COMPOSE_PUBLIC) rm -sf tunnel
	$(DOCKER_COMPOSE) up -d
