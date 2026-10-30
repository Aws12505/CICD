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
 *
 * It also holds the database viewer's tunnels: Laravel asks for one with
 * the same secret, the gateway opens SSH to the server and listens on a port
 * reachable only inside the control plane's Docker network, forwarding each
 * connection to that server's MySQL container. MySQL is never exposed.
 */

const http = require('node:http');
const net = require('node:net');
const { WebSocketServer } = require('ws');
const { Client } = require('ssh2');
const { hostKeyMatches, containerCommand, Recorder, mysqlAddressCommand, parseAddress, secretMatches } = require('./lib');

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
  const response = await fetch(`${LARAVEL_URL}/internal/${path}`, {
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

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', (chunk) => {
      body += chunk;
      if (body.length > 1024 * 1024) req.destroy();
    });
    req.on('end', () => {
      try { resolve(JSON.parse(body || '{}')); } catch (error) { reject(error); }
    });
    req.on('error', reject);
  });
}

/** One SSH connection; resolves when it is ready. */
function connectSsh(details) {
  return new Promise((resolve, reject) => {
    const ssh = new Client();
    ssh.on('ready', () => resolve(ssh));
    ssh.on('error', reject);
    ssh.connect({
      host: details.host,
      port: details.port,
      username: details.user,
      privateKey: details.private_key,
      readyTimeout: 15000,
      keepaliveInterval: 20000,
      hostVerifier: (key) => hostKeyMatches(details.host_key, key),
    });
  });
}

function execOutput(ssh, command) {
  return new Promise((resolve, reject) => {
    ssh.exec(command, (err, stream) => {
      if (err) return reject(err);
      let output = '';
      stream.on('data', (data) => { output += data.toString('utf8'); });
      stream.stderr.on('data', () => {});
      stream.on('close', () => resolve(output));
    });
  });
}

/**
 * A tunnel to a server's MySQL for the database viewer. Closes after the
 * idle and maximum limits the terminal uses, and tells Laravel why.
 */
async function openTunnel(details) {
  const ssh = await connectSsh(details);
  const address = parseAddress(await execOutput(ssh, mysqlAddressCommand()));

  if (!address) {
    ssh.end();
    throw new Error('MySQL is not running on that server');
  }

  let active = 0;
  let closed = false;
  let idle;

  const listener = net.createServer((socket) => {
    active += 1;
    clearTimeout(idle);
    ssh.forwardOut('127.0.0.1', 0, address, 3306, (err, stream) => {
      if (err) {
        socket.destroy();
        return;
      }
      socket.pipe(stream).pipe(socket);
      stream.on('close', () => socket.destroy());
      socket.on('error', () => stream.close());
    });
    socket.on('close', () => {
      active -= 1;
      if (active === 0) touch();
    });
  });

  const close = async (reason) => {
    if (closed) return;
    closed = true;
    clearTimeout(idle);
    clearTimeout(maxTimer);
    listener.close();
    try { ssh.end(); } catch {}
    try {
      await internal('db-viewer/finish', { session_id: details.session_id, reason });
    } catch (error) {
      console.error(`db session ${details.session_id}: could not record its end: ${error.message}`);
    }
  };

  const touch = () => {
    clearTimeout(idle);
    idle = setTimeout(() => close('idle timeout'), IDLE_MS);
  };
  const maxTimer = setTimeout(() => close('maximum session length reached'), MAX_MS);
  ssh.on('close', () => close('the server closed the connection'));
  ssh.on('error', (error) => close(`ssh error: ${error.message}`));

  await new Promise((resolve) => listener.listen(0, '0.0.0.0', resolve));
  touch();

  return listener.address().port;
}

const server = http.createServer(async (req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'text/plain' }).end('ok');
    return;
  }

  if (req.method === 'POST' && req.url === '/internal/tunnels') {
    // The same 404 for a wrong secret as for an unknown path.
    if (!secretMatches(SECRET, req.headers['x-gateway-secret'])) {
      res.writeHead(404).end();
      return;
    }

    try {
      const port = await openTunnel(await readJson(req));
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ port }));
    } catch (error) {
      res.writeHead(502, { 'Content-Type': 'application/json' }).end(JSON.stringify({ error: error.message }));
    }
    return;
  }

  res.writeHead(404).end();
});

const wss = new WebSocketServer({ server, path: '/terminal/ws', maxPayload: 1024 * 1024 });

wss.on('connection', async (ws, req) => {
  const token = new URL(req.url, 'http://gateway').searchParams.get('token') || '';
  let session;

  try {
    session = await internal('terminal/redeem', { token });
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
      await internal('terminal/finish', {
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
