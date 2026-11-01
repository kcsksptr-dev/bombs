# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

A web version of the co-op board game Bomb Busters. Players use their phones on the same local network, and one
machine runs the Docker stack behind the shared local Traefik proxy (`/opt/projects/dev/traefik`). Stack: Laravel 13
on PHP 8.5, Inertia v3 and Vue 3 (`<script setup>`, plain JS), Tailwind 4, Laravel Reverb for WebSockets,
PostgreSQL 18 and Redis 8.

## Commands

Everything runs inside Docker via the Makefile. Nothing PHP- or Node-related should run on the host. Make targets
exec into the `cli` container as `www-data`.

```
make setup                  # first-time: build, start, composer/npm install, asset build, migrate
make start | stop | restart | status | log
make cli                    # shell in the cli container (bombbusters_local_cli)
make vite                   # Vite dev server with HMR (port 5173, reachable from phones)
make npm-build              # production asset build into public/build
make test                   # php artisan test
make test filter=LobbyTest  # single test class or method
make test-static-cs         # Pint check;  make php-coding-style  to fix
make laravel-migrate        # php artisan migrate
make urls                   # print the localhost / LAN / nip.io URLs
make public                 # put the game online (Cloudflare quick tunnel), prints the https link
make public-url | public-log | public-stop
```

For one-off commands: `docker exec -u www-data bombbusters_local_cli php artisan …` (or `composer …`, `npm …`).

## Docker layout

This follows the same pattern as the user's other Laravel projects (e.g. `hungarocontrol/cms`):

- `.makefile/*.mk`: make targets split by area. `docker.mk` builds the `docker compose` command from
  `.environment/.env.docker-compose` (`APP_SLUG=bombbusters`, `APP_ENV=local`).
- `.docker/Dockerfile`: Alpine and PHP 8.5. The `local` stage remaps `www-data` to the host UID/GID (build args from
  make), so files written by php-fpm, Reverb, the worker and make targets are owned by the developer. If you change
  this, run `make build restart`.
- `.docker/compose/docker-compose.local.yml`: services `cli` (idle, used for exec and Vite on :5173),
  `frontend` (supervisord running nginx and php-fpm), `reverb` (`reverb:start` on :8080), `worker`
  (`queue:work`), `db` (host port 5442) and `cache`.
- `.environment/.env.local`: **the real app config**. Docker injects it as container env vars. The root `.env` is
  intentionally empty and only exists because some artisan installers require the file. To change config, edit
  `.environment/.env.local` and run `make restart`.

## Going online (`make public`)

`.docker/compose/docker-compose.public.yml` is layered on top of the local compose file. It adds a `tunnel`
container (`cloudflared` quick tunnel → `http://traefik:80`; no account needed, random
`https://<name>.trycloudflare.com` address per run) and sets `APP_DEBUG=false` on all PHP containers. `make public`
also builds assets and removes `public/hot`, because the Vite dev server isn't reachable from outside. The address is
written to `storage/app/public_url` and read by `App\Support\PublicUrl`; while it's set, room QR codes and links
use it. `make public-stop` removes the tunnel and the file and recreates the containers with debug back on.
Cloudflare terminates HTTPS and forwards `X-Forwarded-Proto`, so with `trustProxies('*')` Laravel and `echo.js`
switch to https/wss on their own.

Online safety: rate limiters in `AppServiceProvider` (`guests` and `rooms` per IP, `play` per player); a 429 on an
Inertia request becomes a friendly `errors.too_fast` message (`bootstrap/app.php`). `bombbusters:prune` runs daily
in the `scheduler` container and deletes rooms idle for 24h and guests gone 30 days with no rooms and no campaign
progress.

## Networking (important for anything realtime)

Traefik routes four kinds of hostname to the app: `bombbusters.localhost`, any bare IPv4 (the LAN IP that phones
use), `bombbusters.<ip>.nip.io` and `*.trycloudflare.com` (the public tunnel). On the same hosts, paths `/app/*` (WebSocket) and `/apps/*` (Reverb HTTP API) go to the
`reverb` container instead of `frontend`. Consequences:

- Don't define web routes under `/app/` or `/apps/`. Reverb owns those paths.
- The browser connects to Reverb at the same host and port as the page (`resources/js/echo.js` builds this from
  `window.location`), so `VITE_REVERB_HOST` and `VITE_REVERB_PORT` are deliberately not used.
- PHP publishes broadcasts directly to `reverb:8080` over the Docker network (`REVERB_HOST=reverb`).
- Proxies are trusted (`trustProxies(at: '*')` in `bootstrap/app.php`).
- `vite.config.js` uses `LAN_IP` (passed in by make) for the dev-server origin and HMR host, so phones can load dev
  assets.

## App structure

- **Players are passwordless guest `User`s.** The first visit asks for a name, then `PlayerController` creates the
  user and logs in with remember-me, so the device keeps its identity across sleeps and browser restarts. Guests who
  hit an `auth` route are redirected to `/` and then sent back to the intended URL, which is how a QR room link works
  for a new phone.
- **Every lobby mutation goes through `App\Services\LobbyService`.** Each one locks the room row
  (`lockForUpdate`), applies the change, and after commit broadcasts `RoomUpdated` on the presence channel
  `room.{code}`. Rule violations throw `ValidationException` under the `lobby` key, so the frontend shows them from
  `errors.lobby`.
- **Broadcasts are notifications only.** `RoomUpdated` carries no state (at most metadata such as
  `kicked_user_id`). Clients respond with `router.reload({ only: [...] })` and fetch their own view over HTTP. Keep
  this pattern for the game: hidden per-player information then never travels over a shared channel.
  `components/RoomPresence.vue` wraps the channel: online members, server updates, and a resync after the socket
  reconnects or the phone screen wakes.
- Rooms: `Room` (code is the route key, matched case-insensitively; status is the `RoomStatus` enum
  lobby/playing/finished) and `RoomPlayer` (seat 1..capacity, `is_ready`). Player limits and the code alphabet live in
  `config/bombbusters.php`. Rooms support 2–8 players; the printed game is 2–5, and 6+ uses this edition's scaled
  rules.
- Inertia pages are in `resources/js/pages/` (`Home`, `Room/Lobby`, `Room/Game`)
  and use `layouts/AppLayout.vue` (header with the language toggle). `RoomController::roomProps()` is the public room
  payload, so never put hidden data there.
- QR and join links use `config('bombbusters.lan_ip')` (the `LAN_IP` env from make), never the request host,
  because `bombbusters.localhost` doesn't resolve on phones.

## Game engine

- **`App\Game\GameEngine` holds the rules as pure functions over a plain-array state.** Each method takes a state
  and returns the next one, or throws `GameRuleException` with a translation key. It has no DB, HTTP or
  broadcasting, so rules are unit-tested directly (`tests/Unit/GameEngineTest.php`). The state shape and the wire
  model are documented at the top of the class.
- **Wires:** blue 1–12 (×4), yellow x.1, red x.5, sorted on stands by `rank` (value × 10). A wire's *kind* is what a
  player names when cutting: a blue number or `'yellow'`. Red is never named: hitting a red wire blows the bomb, and
  a player with only red left uses **Reveal Red**. Setup is exactly one info-token reveal per player, on every
  mission (a fixed rule, not a setting). Info tokens go on blue wires only, and a failed cut on a yellow
  wire leaves an info token of `'yellow'`.
- **Missions replace difficulty.** `config/missions.php` is the campaign, numbered 1..n, as pure data. Its header
  documents the format: detonator bonus, yellow/red `count`/`pool` (pool = public candidate values, count = how many
  are secretly dealt), an optional `sequence` (kinds that must be finished in order), and equipment (`count` random
  or explicit `cards`, plus an unlock `rule`: cut_pair / cut_all / after_mistake / from_start). The shipped
  16-mission campaign is original, following the printed game's progression; the printed missions can be typed in
  using the same format. Every mission needs `mission.{n}.name` and `.briefing` in both `lang/*.json`
  (`CampaignTest` checks this, along with the data itself). `rooms.mission` holds the room's current mission.
- **Sequences:** the engine checks the kind a player *names* (never the target), so a refusal leaks nothing.
  `allowedHeldValues()` and `sequenceProgress()` drive the UI. When the order blocks every move, the player must
  **pass** (`mustPass()`); without that, a player holding only locked numbers would freeze the game.
- **Equipment** catalog: `config/bombbusters.php` → `equipment` (effects in `GameEngine::useEquipment()`): label,
  post_it, radar, stabilizer, rewinder. It's usable once, by the current player, and doesn't end the turn.
  `detonator.ticked` counts every mistake ever, for after_mistake (the Rewinder doesn't undo it).
- **Campaign progress:** a win records `mission_completions` for every player (`GameService::recordCompletion`).
  After a win, the host's "Next mission" (`POST …/game/next-mission`) returns to the lobby with mission + 1. It never
  advances after a loss.
- **Persistence and moves:** `games.state` (JSON, one row per room) stores everything, including hidden wires.
  `App\Services\GameService` locks the row, runs the engine, saves and broadcasts `RoomUpdated` (no payload),
  turning `GameRuleException` into a translated `errors.game`. `LobbyService::start()` deals the game. The host
  captains a room's first game; `rooms.last_captain_seat` then passes it to the next seat after every game, however
  it ended.
- **Undo:** `GameService::apply()` stores the state before every move in `game_snapshots`; the host's
  `POST …/game/undo` pops the latest one, step by step, also after a win or loss (an undone win removes the
  `mission_completions` it recorded). The restored state gets a public `undo` log entry, so everyone sees it. There
  is no redo. Snapshots hold hidden wires, so only their count (`game.undoSteps`) reaches clients.
- **Push and nudges:** `App\Jobs\SendPush` (queued, one per recipient) sends web push from `GameService`: automatically
  to the player the game starts waiting for, and via the 👋 nudge (`POST …/game/nudge/{user}`, with a cooldown). Nudges
  also broadcast metadata so an awake phone reacts. Push needs HTTPS, so it only works through `make public`, and each
  new tunnel address means re-allowing notifications; `components/PushToggle.vue` stays hidden on http. iPhones need
  the site on the Home Screen. Keys are `VAPID_*` in `.environment/.env.local`. Endpoints are allow-listed
  (`PushSubscription::ENDPOINT_RULE`) because the server POSTs to them. After env changes during a tunnel session, use
  `make public-reload`; `make restart` would drop `APP_DEBUG=false`.
- **`App\Game\GameView::for($state, $viewer, …)`** is the only way state reaches a client. Hidden wires carry
  neither value nor colour, and the cut board shows yellow/red totals but not which values. `GameTest` asserts
  this. The `log` holds structured public events; `components/game/GameLog.vue` turns them into sentences in the
  reader's language, and `CutFx.vue` animates new ones (snip / miss / boom / win; off with reduced motion).
- **Double Detector** (each player's one use per game, `GameEngine::doubleDetector()`): point at two wires on one
  teammate's stand and name a kind. If either matches, it's cut (the left one if both match). On a miss the detonator
  ticks (the Stabilizer applies), nothing is cut (so a red wire among them doesn't explode), and
  `state.pending` = `detector_reveal` makes the targeted player choose which of the two gets an info token
  (`revealForDetector()`; info can be a number, 'yellow' or 'red'). While `pending` is set, `assertTurn()` blocks
  every other action and the view's `me.myTurn` is false. ⚠️ The miss handling (teammate's choice, no red
  explosion) is our reading of the printed rule; change it here if the rulebook says otherwise.

## Frontend gotchas (learned the hard way)

- **Use `resources/js/useServerActions.js` for every POST and broadcast resync on a page.** Inertia v3
  `router.reload()` is an async visit that runs in parallel with others, so an older response can land after a newer
  one and put a phone back on an earlier turn. The composable runs one reload at a time (queueing one follow-up),
  cancels an in-flight reload when the player acts, defers broadcasts while the player's own POST is in flight, and
  has a watchdog so `busy` can't stick. Disable inputs with its `busy` ref.
- **Selections (target wire, info-token pick, equipment target) reset only when `game.logLength` changes.** Resyncs
  that change nothing must not clear what the player is in the middle of choosing.
- **echo-vue shares one subscription per channel across components** (reference-counted). When going from Lobby to
  Game, the channel is reused and Echo's `here()` never fires again. `RoomPresence.vue` therefore reads members from
  the Pusher subscription (`subscription.members`, `pusher:member_added/removed`) and unbinds on unmount.
- `data-testid` attributes (and the hidden `data-busy` span on the game page) are hooks for browser tests. Keep them
  when restyling.
- The design system lives in `resources/css/app.css` (`@theme` colours, `.card`, `.btn`, `.chip`, `.input`,
  animations). Use those classes rather than one-off styling. The Fredoka font is downloaded by the Vite fonts
  plugin at build time and bundled, so phones don't need internet.

## Translations (English + Hungarian, both required)

- One dictionary per language: `lang/en.json` and `lang/hu.json`, with flat dotted keys (`lobby.start`) and
  Laravel `:placeholder` syntax. PHP uses `__('key')`. The same file is shared to Vue as the `translations` prop and
  read with `useT()` from `resources/js/i18n.js`. Never hard-code user-facing text in either PHP or Vue.
- **Every new key must go into both files.** A test (`LobbyTest::test_english_and_hungarian_dictionaries_have_the_same_keys`)
  fails if they differ.
- Validation messages in Hungarian come from `lang/hu/validation.php`, which only covers the rules in use. Add new
  rules and attribute names there.
- Locale order: `users.locale`, then the session, then the phone's `Accept-Language`, then `en`
  (`App\Http\Middleware\SetLocale`). `POST /locale` switches it.

## Tests

`make test` runs PHPUnit on in-memory SQLite (`pdo_sqlite` is in the image). The containers export the dev config as
real environment variables, so `phpunit.xml` overrides each test value with both `<env force="true">` and
`<server force="true">`. Don't remove those: without them, `RefreshDatabase` runs against the dev Postgres and wipes it.

## Game design constraints

- Bomb Busters depends on hidden information: each player sees only their own wire tiles. The server must be
  authoritative. Never send other players' hidden tiles to a client, whether in Inertia props or in broadcast
  payloads. Use private or presence channels for per-player data.
- Phones sleep and drop sockets. A player's seat belongs to their guest `User`, not to the socket, so the game must
  rebuild everything from server state when a device comes back.
- Base-game rules cover 2–5 players. Scaling to 6–8 (wire distribution, detonator length, etc.) is a design decision
  of this edition. Keep those numbers in `config/bombbusters.php` rather than scattering them through the code.
