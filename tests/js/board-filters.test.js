import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mountBoardFilters } from '../../src/Resources/public/assets/board-filters.js';

const settle = () => new Promise((done) => setImmediate(done));

function setup(context, result, ok = true) {
  const original = globalThis.FormData;
  globalThis.FormData = class {
    constructor(form) {
      return form.values();
    }
  };
  context.after(() => (globalThis.FormData = original));
  const attributes = new Map();
  const expression = {
    value: 'status = open',
    addEventListener(name, handler) {
      this[name] = handler;
    },
    setAttribute: (key, value) => attributes.set(key, value),
    removeAttribute: (key) => attributes.delete(key),
    focus() {
      this.focused = true;
    },
  };
  const retained = () => ({
    children: [],
    replaceChildren() {
      this.children = [];
    },
    append(input) {
      this.children.push(input);
    },
  });
  const simpleRetained = retained();
  const advancedRetained = retained();
  const controls = {};
  const residual = { value: '' };
  const residualText = {};
  const residualSummary = { hidden: true };
  const focus = {
    focus() {
      this.focused = true;
    },
  };
  const simple = {
    hidden: false,
    action: 'http://localhost/projects/1',
    values: () => [
      ['status', 'open'],
      ['mode', 'simple'],
    ],
    querySelector: (selector) =>
      ({
        '[data-retained-filters]': simpleRetained,
        '[data-simple-controls]': controls,
        '[data-residual-query]': residual,
        '[data-residual-text]': residualText,
        '[data-residual-summary]': residualSummary,
      })[selector] || focus,
  };
  const advanced = {
    hidden: true,
    action: 'http://localhost/projects/1',
    values: () => [
      ['mode', 'advanced'],
      ['dql', expression.value],
    ],
    elements: { namedItem: () => expression },
    querySelector: (selector) =>
      selector === '[data-advanced-retained-summary]' ? {} : advancedRetained,
    addEventListener(name, handler) {
      this[name] = handler;
    },
  };
  const error = { hidden: true };
  const buttons = [{ disabled: false }, { disabled: false }];
  const root = {
    dataset: { endpoint: '/projects/1/filters', mode: 'simple' },
    ownerDocument: { createElement: () => ({}) },
    querySelector: (selector) =>
      ({
        '[data-simple-filters]': simple,
        '[data-advanced-filters]': advanced,
        '[data-filter-error]': error,
      })[selector],
    querySelectorAll: () => buttons,
    addEventListener(name, handler) {
      this[name] = handler;
    },
    setAttribute() {},
    removeAttribute() {},
  };
  const requests = [];
  const navigations = [];
  let respond = async () => ({ ok, json: async () => result });
  mountBoardFilters(root, {
    request: (url) => {
      requests.push(url);
      return respond();
    },
    navigate: (url) => navigations.push(url),
  });
  const click = (mode) =>
    root.click({
      target: { closest: () => ({ dataset: { filterMode: mode } }) },
      preventDefault() {},
    });
  return {
    root,
    simple,
    advanced,
    expression,
    attributes,
    error,
    buttons,
    controls,
    residual,
    residualText,
    residualSummary,
    simpleRetained,
    advancedRetained,
    requests,
    navigations,
    click,
    respond: (handler) => (respond = handler),
  };
}

test('simple filters become a DQL draft without navigating or losing retained plugin values', async (context) => {
  const state = setup(context, {
    expression: 'status = "open"',
    advancedRetained: { filters: { plugin: { checked: false, ids: [2, 3] } } },
  });
  state.click('advanced');
  await settle();
  assert.equal(state.expression.value, 'status = "open"');
  assert.equal(state.simple.hidden, true);
  assert.equal(state.advanced.hidden, false);
  assert.equal(state.root.dataset.mode, 'advanced');
  assert.equal(state.expression.focused, true);
  assert.deepEqual(state.navigations, []);
  assert.deepEqual(
    state.advancedRetained.children.map(({ name, value }) => [name, value]),
    [
      ['filters[plugin][checked]', '0'],
      ['filters[plugin][ids][0]', '2'],
      ['filters[plugin][ids][1]', '3'],
    ],
  );
});

test('returning to dropdowns adopts native controls and keeps a visible residual expression', async (context) => {
  const state = setup(context, {
    controls: '<select name="assignee"></select>',
    simpleRetained: { q: 'sunflower' },
    residual: '(priority = high OR priority = urgent)',
  });
  state.root.dataset.mode = 'advanced';
  state.simple.hidden = true;
  state.advanced.hidden = false;
  state.expression.value = 'assignee = me AND (priority = high OR priority = urgent)';
  state.click('simple');
  await settle();
  assert.equal(state.controls.innerHTML, '<select name="assignee"></select>');
  assert.equal(state.residual.value, '(priority = high OR priority = urgent)');
  assert.equal(state.residualText.textContent, state.residual.value);
  assert.equal(state.residualSummary.hidden, false);
  assert.equal(state.root.dataset.mode, 'simple');
  assert.equal(state.simple.hidden, false);
  assert.equal(state.advanced.hidden, true);
  assert.deepEqual(
    state.simpleRetained.children.map(({ name, value }) => [name, value]),
    [['q', 'sunflower']],
  );
  assert.equal(new URL(state.navigations[0]).searchParams.get('mode'), 'simple');
});

test('invalid DQL stays editable and never switches modes or navigates', async (context) => {
  const state = setup(context, { message: 'DQL an Position 9: Ein Wert wird erwartet.' }, false);
  state.root.dataset.mode = 'advanced';
  state.expression.value = 'status =';
  state.click('simple');
  await settle();
  assert.equal(state.expression.value, 'status =');
  assert.equal(state.root.dataset.mode, 'advanced');
  assert.equal(state.error.hidden, false);
  assert.match(state.error.textContent, /Position 9/);
  assert.equal(state.attributes.get('aria-invalid'), 'true');
  assert.deepEqual(state.navigations, []);
  assert.ok(state.buttons.every((button) => !button.disabled));
});

test('DQL applies explicitly after validation and preserves a portable GET URL', async (context) => {
  const state = setup(context, {});
  state.root.dataset.mode = 'advanced';
  state.expression.value = 'title ~ "A & B"';
  state.expression.input();
  assert.equal(state.requests.length, 0);
  state.advanced.submit({ preventDefault() {} });
  await settle();
  const destination = new URL(state.navigations[0]);
  assert.equal(destination.searchParams.get('dql'), 'title ~ "A & B"');
  assert.equal(destination.searchParams.get('mode'), 'advanced');
});

test('a stale conversion cannot overwrite a draft typed while the request was pending', async (context) => {
  const state = setup(context, {});
  state.root.dataset.mode = 'advanced';
  let finish;
  state.respond(() => new Promise((done) => (finish = done)));
  state.click('simple');
  state.click('simple');
  state.expression.value = 'status = closed';
  finish({ ok: true, json: async () => ({ controls: 'stale', simpleRetained: {}, residual: '' }) });
  await settle();
  assert.equal(state.requests.length, 1);
  assert.equal(state.root.dataset.mode, 'advanced');
  assert.equal(state.expression.value, 'status = closed');
  assert.match(state.error.textContent, /inzwischen geändert/);
});

test('a connection failure leaves the current draft and mode intact', async (context) => {
  const state = setup(context, {});
  state.respond(async () => {
    throw new Error('Offline');
  });
  state.click('advanced');
  await settle();
  assert.equal(state.root.dataset.mode, 'simple');
  assert.equal(state.error.textContent, 'Die Filter konnten nicht geladen werden.');
  assert.ok(state.buttons.every((button) => !button.disabled));
});
