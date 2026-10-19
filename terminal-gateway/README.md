# Terminal gateway

Browser terminals to client servers and their containers. The browser talks
WebSocket to this service; this service talks SSH to the server. The private
key is fetched from Laravel per session and never reaches the browser.

1. Generate a secret and put the same value in the control plane's `.env`:

   ```
   TERMINAL_GATEWAY_SECRET=<openssl rand -hex 32>
   ```

2. `docker compose up -d terminal-gateway` (it is in the control plane's
   compose file, listening on `127.0.0.1:42071`).

3. In the host nginx server block for the control plane, add:

   ```nginx
   location /terminal/ws {
       proxy_pass http://127.0.0.1:42071;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_read_timeout 4h;
   }
   ```

Sessions end after 30 minutes without input or 4 hours in total
(`IDLE_TIMEOUT_SECONDS`, `MAX_SESSION_SECONDS`). Each one is recorded as an
asciicast transcript, listed and replayable under the server's Recordings.

`npm test` runs the unit tests.
