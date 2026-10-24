import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
  new URL('../../src/Resources/public/assets/timer.js', import.meta.url),
  'utf8',
).replace(/^import .*;\n/gm, '');

function fixture() {
  let now = 100_000;
  const intervals = new Set();
  const readout = { textContent: '' };
  const panel = {
    dataset: { state: 'running', seconds: '30', carry: '0', token: 'test-token' },
    isConnected: true,
    querySelector: () => null,
    querySelectorAll: () => [readout],
    toggleAttribute() {},
  };
  const context = {
    panel,
    Date: { now: () => now },
    t: (text) => text,
    setInterval: (callback) => {
      intervals.add(callback);
      return callback;
    },
    clearInterval: (callback) => intervals.delete(callback),
    document: {
      querySelector: () => null,
      querySelectorAll: () => [panel],
      addEventListener() {},
    },
    fetch: async () => ({
      ok: true,
      json: async () => ({ state: 'running', seconds: 180, carry: 0, elsewhere: null }),
    }),
  };
  runInNewContext(
    source +
      '\nglobalThis.redraw = () => render(panel); globalThis.resync = () => send(panel, "state");',
    context,
  );

  return {
    readout,
    redraw: () => context.redraw(),
    resync: () => context.resync(),
    tick(milliseconds) {
      now += milliseconds;
      [...intervals].forEach((callback) => callback());
    },
  };
}

test('redrawing a running timer when its dialog closes preserves elapsed time', () => {
  const timer = fixture();
  timer.tick(75_000);
  assert.equal(timer.readout.textContent, '1:45');
  timer.redraw();
  assert.equal(timer.readout.textContent, '1:45');
  timer.tick(10_000);
  assert.equal(timer.readout.textContent, '1:55');
});

test('a server synchronization replaces the running timer origin', async () => {
  const timer = fixture();
  timer.tick(75_000);
  await timer.resync();
  assert.equal(timer.readout.textContent, '3:00');
  timer.tick(10_000);
  timer.redraw();
  assert.equal(timer.readout.textContent, '3:10');
});
