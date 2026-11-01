CMD ?= /bin/sh -c

## Install NodeJS vendor packages
npm-install:
	$(CMD) "npm install"

## Build frontend assets once (what phones load when Vite dev server isn't running)
npm-build:
	$(CMD) "npm run build"

## Run Vite dev server with HMR, reachable from phones on the LAN (Ctrl+C to stop)
vite:
	$(CMDTI) "rm -f public/hot && npm run dev"
