# Finish Line Timer

Record finish-line crossings of a race with a big on-screen clock, assign the times to
participants, and — optionally — keep several devices in sync in real time.

The user interface currently speaks the language of **sailing regattas** (boats, sail
numbers, regatta codes) in German and English. The code itself is sport-neutral; other
sports only need another text set (see `CLAUDE.md`).

- **Frontend:** a single HTML file (`frontend/index.html`, vanilla JS, no build step).
  Works on its own with browser storage, or connected to this server.
- **Backend:** PHP 8.2+ / Symfony 7.4. Serves the frontend, stores races, offers an HTTP
  API and a WebSocket server for real-time sync. SQLite by default; MySQL/MariaDB and
  PostgreSQL are supported.

## Quick start (development)

```bash
composer install
php bin/console app:install                    # creates the database tables
php -S 127.0.0.1:8000 -t public                 # or: symfony serve
php bin/console app:websocket-server -v         # second terminal, port 8080
```

Open http://127.0.0.1:8000/. Under *Settings* you can create a race on the server or
join one by its code. A race can also be opened directly via `http://host/#r=CODE`.

Without the WebSocket server everything still works; clients fall back to HTTP polling
(about every 1.5 s).

The page is a progressive web app: after the first visit it also starts without a
network connection and can be added to the home screen (iPad: Share → *Add to Home
Screen*). Browsers only allow this over **HTTPS** (or on localhost).

## Configuration

Set values in `.env.local` (not committed) or as real environment variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_SECRET` | `change-me` | Symfony secret — set a random value in production |
| `DATABASE_DSN` | `sqlite:%kernel.project_dir%/var/data.sqlite` | PDO DSN, e.g. `mysql:host=127.0.0.1;dbname=timing;charset=utf8mb4` or `pgsql:host=127.0.0.1;dbname=timing` |
| `DATABASE_USER` / `DATABASE_PASSWORD` | – | Credentials for MySQL/PostgreSQL |
| `WS_PORT` | `8080` | Port of `app:websocket-server` |
| `WS_PUBLIC_URL` | – | WebSocket URL for browsers, e.g. `wss://timing.example.org/ws`. Empty: `ws(s)://<host>:WS_PORT/`. `off`: disable WebSockets (polling only) |
| `TRUSTED_PROXIES` | – | Reverse proxies whose `X-Forwarded-*` headers are trusted, e.g. `127.0.0.1,REMOTE_ADDR` |

## Production

1. `composer install --no-dev --optimize-autoloader`, then `php bin/console app:install`.
   Commit the generated `composer.lock`.
2. Point the web server's document root to `public/` (`public/.htaccess` is included for
   Apache with `mod_rewrite`).
3. Run the WebSocket server permanently, e.g. with systemd:

   ```ini
   # /etc/systemd/system/finish-line-timer-ws.service
   [Unit]
   Description=Finish line timer WebSocket server
   After=network.target

   [Service]
   User=www-data
   WorkingDirectory=/var/www/finish-line-timer
   ExecStart=/usr/bin/php bin/console app:websocket-server --host=127.0.0.1
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. Proxy it through the web server so browsers can use `wss://` on the normal port, and set
   `WS_PUBLIC_URL=wss://timing.example.org/ws` and `TRUSTED_PROXIES` accordingly. nginx:

   ```nginx
   server {
       server_name timing.example.org;
       root /var/www/finish-line-timer/public;

       location /ws {
           proxy_pass http://127.0.0.1:8080;
           proxy_http_version 1.1;
           proxy_set_header Upgrade $http_upgrade;
           proxy_set_header Connection "upgrade";
           proxy_read_timeout 120s;
       }

       location / {
           try_files $uri /index.php$is_args$args;
       }

       location ~ ^/index\.php(/|$) {
           fastcgi_pass unix:/run/php/php8.3-fpm.sock;
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           internal;
       }
   }
   ```

With SQLite, both PHP-FPM and the WebSocket service must be able to write `var/`.
Serve the site over HTTPS so the offline start (service worker) works on the devices.

## Tests

```bash
node tests/reducer-parity.mjs        # JS and PHP reducers must behave identically
node tests/text-keys.mjs             # all UI texts exist in every language and text set
npm install && npx playwright install chromium
BASE_URL=http://127.0.0.1:8000/ node tests/e2e/sync-smoke.mjs   # needs running servers
node tests/e2e/offline-start.mjs     # PWA offline start; starts its own server on port 8123
```

## HTTP API

| Method & path | Description |
| --- | --- |
| `GET /api/config` | `{serverUrl, wsUrl, serverTime}` |
| `POST /api/races` | Creates a race with a random 6-character code → `{code, seq, state}` |
| `GET /api/races/{code}` | Snapshot `{code, seq, state}` (codes are case-insensitive) |
| `GET /api/races/{code}/events?since=N` | `{seq, events: [{seq, op}]}`, or `{reset: true, seq, state}` if far behind |
| `POST /api/races/{code}/ops` | Body `{ops: [...]}` → `{results: [{opId, status: applied\|duplicate\|rejected, error?}]}` |

The WebSocket protocol and the operation types are documented in `CLAUDE.md`.
