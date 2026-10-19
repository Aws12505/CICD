'use strict';

/**
 * Does the key the server presented match the known_hosts line the control
 * plane pinned ("host ssh-ed25519 AAAA...")? ssh2 hands the raw key blob,
 * which is the base64 field of that line decoded.
 */
function hostKeyMatches(pinnedLine, presented) {
  if (typeof pinnedLine !== 'string' || !Buffer.isBuffer(presented)) {
    return false;
  }

  const parts = pinnedLine.trim().split(/\s+/);
  const blob = parts.length >= 3 ? parts[2] : parts[1];

  if (!blob) {
    return false;
  }

  const pinned = Buffer.from(blob, 'base64');

  return pinned.length === presented.length && require('node:crypto').timingSafeEqual(pinned, presented);
}

/** POSIX single-quoting, the same rule as App\Support\Shell::quote. */
function quote(value) {
  return `'${String(value).replace(/'/g, `'\\''`)}'`;
}

/** A shell inside a container: bash when it has one, sh otherwise. */
function containerCommand(container) {
  if (!/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/.test(container)) {
    throw new Error('Invalid container name');
  }

  return `docker exec -it ${quote(container)} sh -c ${quote('command -v bash >/dev/null 2>&1 && exec bash || exec sh')}`;
}

/**
 * An asciicast v2 recording of the session's output. Input is not recorded:
 * what is typed is echoed back as output anyway, except passwords, which is
 * exactly what should stay out of a transcript.
 */
class Recorder {
  constructor(cols, rows, limitBytes = 50 * 1024 * 1024) {
    this.started = Date.now();
    this.header = { version: 2, width: cols, height: rows, timestamp: Math.floor(this.started / 1000), env: { TERM: 'xterm-256color' } };
    this.lines = [];
    this.bytes = 0;
    this.limit = limitBytes;
    this.truncated = false;
  }

  elapsed() {
    return Number(((Date.now() - this.started) / 1000).toFixed(3));
  }

  output(text) {
    this.push([this.elapsed(), 'o', text]);
  }

  resize(cols, rows) {
    this.push([this.elapsed(), 'r', `${cols}x${rows}`]);
  }

  push(event) {
    const line = JSON.stringify(event);

    if (this.bytes + line.length > this.limit) {
      this.truncated = true;
      return;
    }

    this.bytes += line.length + 1;
    this.lines.push(line);
  }

  toString() {
    return [JSON.stringify(this.header), ...this.lines].join('\n') + '\n';
  }
}

module.exports = { hostKeyMatches, quote, containerCommand, Recorder };
