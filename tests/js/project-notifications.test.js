import assert from 'node:assert/strict';
import { test } from 'node:test';
import { projectNotificationSettings } from '../../src/Resources/public/assets/project-notifications.js';

function setup(context, saved = false) {
  const listeners = {};
  const input = { checked: saved, defaultChecked: saved, disabled: false };
  const state = { textContent: saved ? 'Stummgeschaltet' : 'Benachrichtigungen aktiv' };
  const feedback = { textContent: '' };
  const errors = new Set();
  const errorBox = {
    textContent: '',
    classList: { add: (name) => errors.add(name), remove: (name) => errors.delete(name) },
  };
  const button = { hidden: false };
  const fields = {
    'input[role="switch"]': input,
    '[data-project-notification-state]': state,
    '[data-project-notification-feedback]': feedback,
    '.form-errors': errorBox,
    'button[type="submit"]': button,
    'input[name="_csrf"]': { value: 'csrf-token' },
  };
  const attributes = new Map([['action', '/api/projects/42/settings/user']]);
  let pending;
  let requests = [];
  let respond = async () => ({
    ok: true,
    json: async () => ({ values: { muted: input.checked } }),
  });
  context.mock.method(globalThis, 'fetch', async (url, options) => {
    requests.push({ url, ...options });
    return respond();
  });
  const form = {
    querySelector: (selector) => fields[selector],
    addEventListener: (name, listener) => {
      listeners[name] = listener;
    },
    setAttribute: (key, value) => attributes.set(key, value),
    getAttribute: (key) => attributes.get(key),
    removeAttribute: (key) => attributes.delete(key),
    requestSubmit: () => {
      pending = listeners.submit({ preventDefault() {} });
    },
  };
  projectNotificationSettings(form);
  return {
    input,
    state,
    feedback,
    errorBox,
    errors,
    button,
    requests,
    attributes,
    change: (value) => {
      input.checked = value;
      listeners.change({ target: input });
      return pending;
    },
    submit: () => listeners.submit({ preventDefault() {} }),
    respond: (callback) => {
      respond = callback;
    },
  };
}

test('the switch immediately saves a boolean through the existing settings API', async (context) => {
  const ui = setup(context);
  assert.equal(ui.button.hidden, true);
  await ui.change(true);
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].url, '/api/projects/42/settings/user');
  assert.equal(ui.requests[0].headers['X-CSRF-Token'], 'csrf-token');
  assert.deepEqual(JSON.parse(ui.requests[0].body), { values: { muted: true } });
  assert.equal(ui.input.checked, true);
  assert.equal(ui.input.defaultChecked, true);
  assert.equal(ui.state.textContent, 'Stummgeschaltet');
  assert.equal(ui.feedback.textContent, 'Gespeichert');
});

test('enabling notifications sends false and survives reopening or reset', async (context) => {
  const ui = setup(context, true);
  await ui.change(false);
  assert.deepEqual(JSON.parse(ui.requests[0].body), { values: { muted: false } });
  assert.equal(ui.input.defaultChecked, false);
  assert.equal(ui.state.textContent, 'Benachrichtigungen aktiv');
});

test('a rejected save restores the last confirmed state and shows the server message', async (context) => {
  const ui = setup(context, true);
  ui.respond(async () => ({ ok: false, json: async () => ({ message: 'Kein Zugriff' }) }));
  await ui.change(false);
  assert.equal(ui.input.checked, true);
  assert.equal(ui.state.textContent, 'Stummgeschaltet');
  assert.equal(ui.errorBox.textContent, 'Kein Zugriff');
  assert.equal(ui.errors.has('visible'), true);
  assert.equal(ui.input.disabled, false);
  assert.equal(ui.feedback.textContent, '');
});

test('connection failures ask the user to check the saved state and permit retry', async (context) => {
  const ui = setup(context);
  ui.respond(async () => {
    throw new TypeError('Failed to fetch');
  });
  await ui.change(true);
  assert.equal(ui.input.checked, false);
  assert.match(ui.errorBox.textContent, /Bitte prüfe den aktuellen Stand/);
  ui.respond(async () => ({ ok: true, json: async () => ({ values: { muted: true } }) }));
  await ui.change(true);
  assert.equal(ui.errors.has('visible'), false);
  assert.equal(ui.input.checked, true);
});

test('pending saves disable the switch and prevent duplicate requests', async (context) => {
  const ui = setup(context);
  let complete;
  ui.respond(
    () =>
      new Promise((resolve) => {
        complete = resolve;
      }),
  );
  const pending = ui.change(true);
  assert.equal(ui.input.disabled, true);
  assert.equal(ui.attributes.get('aria-busy'), 'true');
  await ui.submit();
  assert.equal(ui.requests.length, 1);
  complete({ ok: true, json: async () => ({ values: { muted: true } }) });
  await pending;
  assert.equal(ui.input.disabled, false);
  assert.equal(ui.attributes.has('aria-busy'), false);
});

test('malformed success responses cannot turn an unsaved switch into a saved one', async (context) => {
  const ui = setup(context);
  ui.respond(async () => ({ ok: true, json: async () => ({}) }));
  await ui.change(true);
  assert.equal(ui.input.checked, false);
  assert.equal(ui.errors.has('visible'), true);
});
