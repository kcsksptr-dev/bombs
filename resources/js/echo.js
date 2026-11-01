import { configureEcho } from '@laravel/echo-vue';

// Reverb is served through Traefik on the same host/port as the page (paths /app/* and /apps/*),
// so phones reaching the game via the LAN IP or a nip.io name connect without extra config.
const secure = window.location.protocol === 'https:';
const port = Number(window.location.port) || (secure ? 443 : 80);

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
});
