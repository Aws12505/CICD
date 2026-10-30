'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { hostKeyMatches, containerCommand, quote, Recorder, mysqlAddressCommand, parseAddress, secretMatches } = require('./lib');

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

test('a database tunnel goes to the MySQL container, and only to an address', () => {
  assert.strictEqual(mysqlAddressCommand(), `docker inspect -f '{{(index .NetworkSettings.Networks "pizza_shared").IPAddress}}' mysql`);
  assert.strictEqual(parseAddress('172.18.0.4\n'), '172.18.0.4');
  assert.strictEqual(parseAddress(''), null);
  assert.strictEqual(parseAddress('172.18.0.999'), null);
  assert.strictEqual(parseAddress('evil.example.com'), null);
});

test('the shared secret is compared exactly', () => {
  assert.strictEqual(secretMatches('a'.repeat(32), 'a'.repeat(32)), true);
  assert.strictEqual(secretMatches('a'.repeat(32), 'b'.repeat(32)), false);
  assert.strictEqual(secretMatches('', ''), false);
  assert.strictEqual(secretMatches('a'.repeat(32), undefined), false);
});
