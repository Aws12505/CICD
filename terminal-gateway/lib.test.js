'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { hostKeyMatches, containerCommand, quote, Recorder } = require('./lib');

const BLOB = 'AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyDataHere00000000000000';

test('only the pinned host key is accepted', () => {
  const pinned = `example.com ssh-ed25519 ${BLOB}`;

  assert.strictEqual(hostKeyMatches(pinned, Buffer.from(BLOB, 'base64')), true);
  assert.strictEqual(hostKeyMatches(pinned, Buffer.from('AAAAC3NzaC1lZDI1NTE5AAAAIOther', 'base64')), false);
  assert.strictEqual(hostKeyMatches(null, Buffer.from(BLOB, 'base64')), false);
  assert.strictEqual(hostKeyMatches('', Buffer.from(BLOB, 'base64')), false);
});

test('container names are validated and quoted', () => {
  assert.match(containerCommand('acme_live_hiring'), /^docker exec -it 'acme_live_hiring' sh -c /);
  assert.throws(() => containerCommand('x; rm -rf /'));
  assert.strictEqual(quote("it's"), `'it'\\''s'`);
});

test('recordings are asciicast v2 and stop growing at the limit', () => {
  const recorder = new Recorder(80, 24, 200);

  recorder.output('hello\r\n');
  recorder.output('x'.repeat(500));

  const [header, ...events] = recorder.toString().trim().split('\n').map((l) => JSON.parse(l));

  assert.strictEqual(header.version, 2);
  assert.strictEqual(header.width, 80);
  assert.deepStrictEqual(events.map((e) => e[2]), ['hello\r\n']);
  assert.strictEqual(recorder.truncated, true);
});
