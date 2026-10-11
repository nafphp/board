import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const source = (
  await readFile(new URL('../../src/Resources/public/assets/choice.js', import.meta.url), 'utf8')
)
  .replace(/^import .*\n/gm, '')
  .replace(/export function /g, 'function ');

function setup() {
  function node() {
    return {
      dataset: {},
      hidden: false,
      value: '',
      children: [],
      listeners: {},
      attributes: {},
      style: { setProperty() {} },
      classList: { toggle() {} },
      append(child) {
        this.children.push(child);
      },
      replaceChildren() {
        this.children = [];
      },
      get childNodes() {
        return this.children;
      },
      setAttribute(key, value) {
        this.attributes[key] = value;
      },
      removeAttribute(key) {
        delete this.attributes[key];
      },
      addEventListener(key, callback) {
        this.listeners[key] = callback;
      },
      dispatchEvent(event) {
        (this.events ??= []).push(event.type);
      },
      focus() {
        this.focused = true;
      },
      scrollIntoView() {},
    };
  }
  const native = node(),
    trigger = node(),
    popup = node(),
    search = node(),
    list = node(),
    control = node(),
    value = node(),
    status = node(),
    wrap = node();
  list.id = 'tickets';
  popup.hidden = true;
  const parts = {
    '[data-choice-native]': native,
    '[data-choice-trigger]': trigger,
    '[data-choice-popup]': popup,
    '[data-choice-search]': search,
    '.choice-list': list,
    'select, [data-choice-source-value]': control,
    '[data-choice-value]': value,
    '.choice-empty': status,
    '.choice-search-wrap': wrap,
  };
  const root = Object.assign(node(), {
    isConnected: true,
    dataset: { choiceSource: '/suggestions', choiceEmpty: 'Search', choiceLabel: 'Ticket' },
    querySelector: (selector) => parts[selector],
    querySelectorAll: () => [],
  });
  trigger.getBoundingClientRect = () => ({ width: 400, top: 100, bottom: 140, left: 20 });
  const scope = { querySelectorAll: () => [root] };
  const queries = [];
  let answer,
    canceled = 0;
  const context = {
    document: {
      readyState: 'loading',
      baseURI: 'https://example.test/',
      addEventListener() {},
      createElement: node,
    },
    window: { addEventListener() {} },
    innerWidth: 1200,
    innerHeight: 900,
    WeakMap,
    URL,
    Event,
    CustomEvent: class extends Event {},
    setTimeout,
    t: (text) => text,
    createSearchLoader(endpoint, update) {
      answer = update;
      return { search: (query) => queries.push(query), cancel: () => canceled++ };
    },
  };
  runInNewContext(source + '\nthis.enhance = enhanceChoices; this.open = openChoice;', context);
  context.enhance(scope);
  return {
    root,
    native,
    trigger,
    popup,
    search,
    list,
    control,
    value,
    status,
    queries,
    open: () => context.open(root),
    answer: (data) => answer(data),
    canceled: () => canceled,
  };
}

test('remote choices preserve native form values and render titles as text', () => {
  const ui = setup();
  ui.open();
  assert.deepEqual(ui.queries, ['']);
  ui.answer({ state: 'ready', options: [{ value: '2', label: 'NAF-2 · <img src=x>' }] });
  const option = ui.list.children[0];
  assert.equal(option.children[0].textContent, 'NAF-2 · <img src=x>');
  option.listeners.click();
  assert.equal(ui.control.value, '2');
  assert.deepEqual(ui.control.events, ['input', 'change']);
  assert.equal(ui.popup.hidden, true);
  assert.equal(ui.native.hidden, true);
  assert.equal(ui.trigger.focused, true);
  ui.open();
  ui.answer({ state: 'loading' });
  ui.answer({ state: 'error' });
  assert.equal(ui.control.value, '2');
  assert.equal(ui.value.children[0].textContent, 'NAF-2 · <img src=x>');
  assert.match(ui.status.textContent, /Suche konnte nicht/);
});

test('required remote fields open their search and Escape cancels only the choice', () => {
  const ui = setup();
  let prevented = 0,
    stopped = 0;
  ui.control.listeners.invalid({ preventDefault: () => prevented++ });
  assert.equal(ui.popup.hidden, false);
  assert.equal(ui.search.focused, true);
  ui.root.listeners.keydown({
    key: 'Escape',
    preventDefault: () => prevented++,
    stopPropagation: () => stopped++,
  });
  assert.equal(ui.popup.hidden, true);
  assert.equal(prevented, 2);
  assert.equal(stopped, 1);
  assert.equal(ui.canceled(), 1);
});

test('remote answers cannot repopulate a removed or closed choice', () => {
  const ui = setup();
  ui.answer({ state: 'ready', options: [{ value: '2', label: 'Closed' }] });
  assert.equal(ui.list.children.length, 0);
  ui.open();
  ui.root.isConnected = false;
  ui.answer({ state: 'ready', options: [{ value: '2', label: 'Late' }] });
  assert.equal(ui.list.children.length, 0);
});
