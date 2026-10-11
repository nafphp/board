import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mountEmailDesigner } from '../../src/Resources/public/assets/email-design.js';
import { configFor, storageFor, systemPrompt } from '../../src/Resources/public/assets/ai/store.js';

test('assistant uses the current global name while keeping existing personal settings', (context) => {
  const original = globalThis.localStorage;
  context.after(() => {
    globalThis.localStorage = original;
  });
  globalThis.localStorage = {
    getItem: () => JSON.stringify({ appName: 'Old name', model: 'local-model' }),
  };
  const root = { dataset: { user: '1', project: '2', appName: 'Studio North' } };
  const config = configFor(root);
  assert.equal(config.appName, 'Studio North');
  assert.equal(config.model, 'local-model');
  assert.match(systemPrompt(config), /"Studio North"/);
  assert.doesNotMatch(systemPrompt(config), /Nafinity AI/);
  assert.equal(storageFor(root).config, 'nafinity:ai:1:config');
});

test('email preview keeps the latest valid draft and sends neither save nor mail requests', async (context) => {
  const original = Object.fromEntries(
    ['FormData', 'fetch', 'setTimeout', 'clearTimeout'].map((key) => [key, globalThis[key]]),
  );
  context.after(() => Object.assign(globalThis, original));
  const values = new Map([
    ['_csrf', 'token'],
    ['sample', 'invitation'],
    ['design[colors][accent]', '#112233'],
    ['design[common][density]', '1.1'],
    ['design[css]', '.email-title { color: var(--accent); }'],
  ]);
  globalThis.FormData = class extends Map {
    constructor() {
      super(values);
    }
  };
  const requests = [];
  globalThis.fetch = (url, options) =>
    new Promise((resolve) => requests.push({ url, options, resolve }));
  let timer;
  globalThis.setTimeout = (callback) => {
    timer = callback;
  };
  globalThis.clearTimeout = () => {};
  const frame = {
    dataset: {},
    srcdoc: 'saved',
    style: {},
    addEventListener() {},
    contentDocument: { body: { getBoundingClientRect: () => ({ height: 710 }) } },
  };
  const status = {};
  const listeners = {};
  const form = {
    dataset: { previewUrl: '/settings/email-design/preview' },
    querySelector: (selector) => (selector === '[data-email-preview]' ? frame : status),
    elements: { namedItem: () => ({ value: 'mobile' }) },
    addEventListener: (type, listener) => {
      listeners[type] = listener;
    },
  };
  mountEmailDesigner(form);
  listeners.input();
  const first = timer();
  values.set('design[colors][accent]', '#445566');
  listeners.input();
  const second = timer();
  assert.equal(requests[0].options.signal.aborted, true);
  assert.equal(frame.dataset.size, 'mobile');
  assert.equal(frame.style.height, '710px');
  const payload = JSON.parse(requests[1].options.body);
  assert.equal(payload.design.colors.accent, '#445566');
  assert.equal(payload.design.common.density, '1.1');
  assert.equal(requests[1].options.headers['X-CSRF-Token'], 'token');
  requests[1].resolve({ ok: true, json: async () => ({ html: 'new draft' }) });
  await second;
  requests[0].resolve({ ok: true, json: async () => ({ html: 'old draft' }) });
  await first;
  assert.equal(frame.srcdoc, 'new draft');
  listeners.change();
  const invalid = timer();
  requests[2].resolve({ ok: false, json: async () => ({ message: 'Invalid CSS' }) });
  await invalid;
  assert.equal(frame.srcdoc, 'new draft');
  assert.equal(status.textContent, 'Invalid CSS');
  assert.ok(requests.every((request) => request.url === '/settings/email-design/preview'));
});
