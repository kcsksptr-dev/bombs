# Bomb Busters

A co-op Bomb Busters game for phones on a local network. One machine runs the Docker stack, and players open
the game in their phone browsers. Nothing needs to be installed on the phones.

Stack: Laravel 13 (PHP 8.5), Inertia and Vue 3, Tailwind 4, Laravel Reverb (WebSockets), PostgreSQL 18, Redis 8.

## Requirements

- Docker with Compose v2
- The shared local Traefik proxy (`/opt/projects/dev/traefik`) running, which provides the `traefik_network` network
  and ports 80/443

## Setup

```shell
make setup
```

This builds the image, starts the containers, installs Composer and npm packages, builds the frontend, migrates
the database and prints the URLs.

## URLs

| Who | URL |
|---|---|
| This machine | http://bombbusters.localhost |
| Phones on the same Wi-Fi | `http://<lan-ip>` (run `make urls`) |
| Phones, by name | `http://bombbusters.<lan-ip>.nip.io` (needs internet access for DNS) |

## Playing online

```shell
make public        # starts a Cloudflare tunnel and prints a link like https://<random>.trycloudflare.com
make public-stop   # back to local network only
```

No Cloudflare account is needed. The link changes every time you run `make public`, works only while this machine
runs the stack, and room QR codes switch to it automatically. Share it only with people you're playing with.

## Everyday commands

```shell
make help        # list all targets
make start|stop|restart|status|log
make cli         # shell in the PHP cli container
make vite        # Vite dev server with hot reload, also reachable from phones (port 5173)
make npm-build   # one-off production asset build (what phones get when Vite isn't running)
make test        # run tests (make test filter=SomeTest)
make php-coding-style   # fix code style with Pint
```
