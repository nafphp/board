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
    contentDocument: {
      documentElement: {},
      body: { getBoundingClientRect: () => ({ height: 710 }) },
    },
  };
  const status = {};
  const listeners = {};
  const form = {
    dataset: { previewUrl: '/settings/email-design/preview' },
    querySelector: (selector) =>
      ({ '[data-email-preview]': frame, '[data-email-preview-status]': status })[selector],
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

test('test send is explicit, uses the live draft, prevents duplicate clicks and reports failure without saving', async (context) => {
  const original = { fetch: globalThis.fetch, FormData: globalThis.FormData };
  context.after(() => Object.assign(globalThis, original));
  const values = new Map([
    ['_csrf', 'token'],
    ['sample', 'notification'],
    ['design[colors][accent]', '#112233'],
  ]);
  globalThis.FormData = class extends Map {
    constructor() {
      super(values);
    }
  };
  const requests = [];
  globalThis.fetch = (url, options) =>
    new Promise((resolve) => requests.push({ url, options, resolve }));
  let send;
  const button = { disabled: false, addEventListener: (_, listener) => (send = listener) };
  const status = {};
  const frame = { addEventListener() {} };
  const form = {
    dataset: { previewUrl: '/preview', testUrl: '/test' },
    querySelector: (selector) =>
      ({
        '[data-email-preview]': frame,
        '[data-email-preview-status]': {},
        '[data-email-test]': button,
        '[data-email-test-status]': status,
      })[selector],
    addEventListener() {},
  };
  mountEmailDesigner(form);
  assert.equal(requests.length, 0, 'mounting never sends mail');
  const first = send();
  await send();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/test');
  assert.equal(JSON.parse(requests[0].options.body).design.colors.accent, '#112233');
  assert.equal(requests[0].options.headers['X-CSRF-Token'], 'token');
  requests[0].resolve({ ok: true, json: async () => ({ message: 'Test sent' }) });
  await first;
  assert.equal(status.textContent, 'Test sent');
  assert.equal(button.disabled, false);
  values.set('design[colors][accent]', '#445566');
  const second = send();
  requests[1].resolve({ ok: false, json: async () => ({ message: 'Transport refused' }) });
  await second;
  assert.equal(status.textContent, 'Transport refused');
  assert.equal(button.disabled, false);
  assert.equal(values.get('design[colors][accent]'), '#445566');
});
