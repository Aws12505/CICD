'use strict';

/*
 * Terminal gateway (decision 21).
 *
 * The browser opens a WebSocket here with a single-use token Laravel issued
 * a moment earlier. The gateway redeems the token over an internal endpoint
 * for the server's address, the decrypted key and the pinned host key, opens
 * the SSH session itself and pipes bytes both ways. The key never leaves the
 * control plane, and a token is worthless after one use or sixty seconds.
 *
 * Every session's output is recorded as an asciicast v2 transcript and
 * handed back to Laravel when it ends, where it can be replayed.
 *
 * Messages from the browser are JSON: {type: "data", data} or
 * {type: "resize", cols, rows}. Output to the browser is raw text.
 */

const http = require('node:http');
const { WebSocketServer } = require('ws');
const { Client } = require('ssh2');
const { hostKeyMatches, containerCommand, Recorder } = require('./lib');

const PORT = Number(process.env.PORT || 8090);
const LARAVEL_URL = (process.env.LARAVEL_URL || 'http://backend').replace(/\/$/, '');
const SECRET = process.env.TERMINAL_GATEWAY_SECRET || '';
const IDLE_MS = Number(process.env.IDLE_TIMEOUT_SECONDS || 1800) * 1000;
const MAX_MS = Number(process.env.MAX_SESSION_SECONDS || 4 * 3600) * 1000;

if (SECRET.length < 32) {
  // Not an exit: under `restart: unless-stopped` that is a crash loop that
  // hides this message. Stay up, say what is missing, and serve nothing.
  console.error(
    'The terminal is switched off: set TERMINAL_GATEWAY_SECRET (32+ characters, the same value as in Laravel) and restart this container.',
  );
  setInterval(() => {}, 1 << 30);
  return;
}

async function internal(path, body) {
  const response = await fetch(`${LARAVEL_URL}/internal/terminal/${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Gateway-Secret': SECRET,
    },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`${path} answered ${response.status}`);
  }

  return response.json();
}

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'text/plain' }).end('ok');
    return;
  }
  res.writeHead(404).end();
});

const wss = new WebSocketServer({ server, path: '/terminal/ws', maxPayload: 1024 * 1024 });

wss.on('connection', async (ws, req) => {
  const token = new URL(req.url, 'http://gateway').searchParams.get('token') || '';
  let session;

  try {
    session = await internal('redeem', { token });
  } catch (error) {
    ws.close(4401, 'This terminal link has expired. Open the terminal again.');
    return;
  }

  const recorder = new Recorder(session.cols || 120, session.rows || 32);
  const ssh = new Client();
  let stream = null;
  let finished = false;
  let idle;

  const finish = async (reason) => {
    if (finished) return;
    finished = true;
    clearTimeout(idle);
    clearTimeout(maxTimer);

    try { stream && stream.close(); } catch {}
    try { ssh.end(); } catch {}
    if (ws.readyState === ws.OPEN) ws.close(1000, reason.slice(0, 100));

    try {
      // Base64, so nothing on the way (Laravel trims request strings) can
      // alter a byte of the transcript.
      await internal('finish', {
        session_id: session.session_id,
        reason,
        recording_b64: Buffer.from(recorder.toString(), 'utf8').toString('base64'),
        truncated: recorder.truncated,
      });
    } catch (error) {
      console.error(`session ${session.session_id}: could not store the recording: ${error.message}`);
    }
  };

  const touch = () => {
    clearTimeout(idle);
    idle = setTimeout(() => finish('idle timeout'), IDLE_MS);
  };
  const maxTimer = setTimeout(() => finish('maximum session length reached'), MAX_MS);
  touch();

  ws.on('message', (raw) => {
    let message;
    try { message = JSON.parse(raw.toString()); } catch { return; }

    if (message.type === 'data' && stream && typeof message.data === 'string') {
      touch();
      stream.write(message.data);
    } else if (message.type === 'resize' && stream) {
      const cols = Math.max(20, Math.min(500, Number(message.cols) || 80));
      const rows = Math.max(5, Math.min(200, Number(message.rows) || 24));
      stream.setWindow(rows, cols, 0, 0);
      recorder.resize(cols, rows);
    }
  });

  ws.on('close', () => finish('closed by the browser'));
  ws.on('error', () => finish('browser connection error'));

  ssh.on('ready', () => {
    const pty = { term: 'xterm-256color', cols: session.cols || 120, rows: session.rows || 32 };
    const opened = (err, s) => {
      if (err) {
        finish(`could not open the session: ${err.message}`);
        return;
      }

      stream = s;
      stream.on('data', (data) => {
        const text = data.toString('utf8');
        recorder.output(text);
        if (ws.readyState === ws.OPEN) ws.send(text);
      });
      stream.stderr.on('data', (data) => {
        const text = data.toString('utf8');
        recorder.output(text);
        if (ws.readyState === ws.OPEN) ws.send(text);
      });
      stream.on('close', () => finish('session ended'));
    };

    if (session.container) {
      ssh.exec(containerCommand(session.container), { pty }, opened);
    } else {
      ssh.shell(pty, opened);
    }
  });

  ssh.on('error', (error) => {
    if (ws.readyState === ws.OPEN) ws.send(`\r\n\x1b[31mSSH error: ${error.message}\x1b[0m\r\n`);
    finish(`ssh error: ${error.message}`);
  });

  ssh.connect({
    host: session.host,
    port: session.port,
    username: session.user,
    privateKey: session.private_key,
    readyTimeout: 15000,
    keepaliveInterval: 20000,
    // Only the pinned key: the same rule as every other SSH connection the
    // control plane makes.
    hostVerifier: (key) => hostKeyMatches(session.host_key, key),
  });
});

server.listen(PORT, () => console.log(`terminal gateway listening on ${PORT}`));
