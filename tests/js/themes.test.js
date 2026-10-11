import assert from 'node:assert/strict';
import { test } from 'node:test';
globalThis.document = { readyState: 'loading', addEventListener() {} };
globalThis.window = { addEventListener() {} };
const { mountThemeManager } = await import('../../src/Resources/public/assets/themes.js');
delete globalThis.document;
delete globalThis.window;

function setup(context) {
  const original = Object.fromEntries(
    ['fetch', 'FormData', 'setTimeout', 'clearTimeout'].map((key) => [key, globalThis[key]]),
  );
  context.after(() => Object.assign(globalThis, original));
  const schema = {
    common: { density: { default: 1 } },
    palettes: {
      classic: { light: { accent: '#112233' }, dark: { accent: '#445566' } },
      anthracite: { light: { accent: '#778899' }, dark: { accent: '#aabbcc' } },
    },
  };
  const fields = Object.fromEntries(
    [
      'id',
      'name',
      'base',
      'enabled',
      'css',
      '_csrf',
      'preview_mode',
      'common[density]',
      'light[accent]',
      'dark[accent]',
    ].map((key) => [key, { value: '', dispatchEvent() {} }]),
  );
  fields.name.value = 'Draft';
  fields.base.value = 'classic';
  fields.enabled.checked = true;
  fields._csrf.value = 'token';
  fields.preview_mode.value = 'light';
  const listeners = {};
  const nodes = {};
  const node = (key) =>
    (nodes[key] ??= {
      addEventListener: (type, listener) => (listeners[key + ':' + type] = listener),
    });
  const form = node('form');
  // Native switches submit a hidden zero and a checkbox with the same name.
  form.elements = { namedItem: (name) => (name === 'enabled' ? { value: '' } : fields[name]) };
  form.querySelector = () => fields.enabled;
  form.dataset = { previewUrl: '/preview' };
  form.querySelectorAll = () => [];
  const style = {};
  const html = { dataset: {} };
  const frame = node('frame');
  frame.contentDocument = { documentElement: html, getElementById: () => style };
  const status = {};
  const picker = node('picker');
  const root = {
    querySelector: (selector) =>
      ({
        '[data-theme-definitions]': { textContent: JSON.stringify({ schema, themes: {} }) },
        '[data-theme-editor]': form,
        '[name="edit_theme"]': picker,
        '[data-theme-frame]': frame,
        '[data-theme-preview-status]': status,
        '[data-theme-duplicate]': node('duplicate'),
        '[data-theme-import]': node('import'),
      })[selector],
  };
  globalThis.FormData = class {
    *[Symbol.iterator]() {
      for (const [key, field] of Object.entries(fields)) yield [key, field.value];
    }
  };
  let timer;
  globalThis.setTimeout = (callback) => (timer = callback);
  globalThis.clearTimeout = () => (timer = null);
  const requests = [];
  globalThis.fetch = (url, options) =>
    new Promise((resolve) => requests.push({ url, options, resolve }));
  mountThemeManager(root);
  const respond = (index, css, ok = true) =>
    requests[index].resolve({ ok, json: async () => (ok ? { css } : { message: css }) });
  return { fields, listeners, style, html, status, picker, requests, respond, run: () => timer() };
}

test('preview stays unsaved and a late response cannot replace newer edits', async (context) => {
  const state = setup(context);
  const first = state.run();
  state.fields.name.value = 'New draft';
  state.listeners['form:input']();
  const second = state.run();
  assert.equal(state.requests[0].options.signal.aborted, true);
  const payload = JSON.parse(state.requests[1].options.body);
  assert.equal(payload.name, 'New draft');
  assert.equal(payload._csrf, 'token');
  assert.equal(state.requests[1].options.headers['X-CSRF-Token'], 'token');
  assert.equal(payload.id, undefined);
  assert.equal(payload.enabled, '1');
  state.respond(1, 'new css');
  await second;
  state.respond(0, 'old css');
  await first;
  assert.equal(state.style.textContent, 'new css');
  assert.equal(state.fields.id.value, '');
  state.fields.preview_mode.value = 'dark';
  state.listeners['form:change']({ target: state.fields.preview_mode });
  assert.equal(state.html.dataset.theme, 'dark');
  assert.equal(state.requests.length, 2);
});

test('invalid preview retains the last valid stylesheet and shows the server error', async (context) => {
  const state = setup(context);
  const first = state.run();
  state.respond(0, 'valid css');
  await first;
  state.listeners['form:input']();
  const second = state.run();
  state.respond(1, 'Invalid CSS', false);
  await second;
  assert.equal(state.style.textContent, 'valid css');
  assert.equal(state.status.textContent, 'Invalid CSS');
});

test('changing the base replaces color defaults and keeps the draft name and CSS', async (context) => {
  const state = setup(context);
  state.fields.css.value = '.custom { display: grid; }';
  state.fields.base.value = 'anthracite';
  state.listeners['form:change']({ target: state.fields.base });
  assert.equal(state.fields['light[accent]'].value, '#778899');
  assert.equal(state.fields['dark[accent]'].value, '#aabbcc');
  assert.equal(state.fields.name.value, 'Draft');
  assert.equal(state.fields.css.value, '.custom { display: grid; }');
});

test('an import must validate before replacing fields and loses to a later user edit', async (context) => {
  const state = setup(context);
  const event = {
    target: {
      files: [
        {
          size: 100,
          text: async () =>
            JSON.stringify({
              version: 1,
              theme: { name: 'Imported', base: 'classic', enabled: true },
            }),
        },
      ],
    },
  };
  const importing = state.listeners['import:change'](event);
  await Promise.resolve();
  assert.equal(state.fields.name.value, 'Draft');
  state.fields.name.value = 'User edit';
  state.listeners['form:input']();
  state.respond(0, 'imported css');
  await importing;
  assert.equal(state.fields.name.value, 'User edit');
  assert.equal(event.target.value, '');
});

test('a validated imported draft updates the actual switch without publishing it', async (context) => {
  const state = setup(context);
  const event = {
    target: {
      files: [
        {
          size: 100,
          text: async () =>
            JSON.stringify({
              version: 1,
              theme: { name: 'Draft import', base: 'classic', enabled: false },
            }),
        },
      ],
    },
  };
  const importing = state.listeners['import:change'](event);
  await Promise.resolve();
  state.respond(0, 'draft css');
  await importing;
  assert.equal(state.fields.enabled.checked, false);
  assert.equal(state.fields.id.value, '');
  assert.equal(state.fields.name.value, 'Draft import');
});
