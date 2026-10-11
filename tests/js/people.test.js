import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import {
  selectedTargets,
  syncSelection,
  syncRoleSummary,
} from '../../src/Resources/public/assets/people.js';

function directory(inputs) {
  const all = {};
  const bar = {};
  const count = {};
  const rows = inputs.map((input) => ({
    querySelector: () => input,
    classList: { toggle: (key, value) => (input.selected = value) },
  }));
  return {
    all,
    bar,
    count,
    querySelector(selector) {
      return { '[data-bulk-all]': all, '[data-bulk-bar]': bar, '[data-bulk-count]': count }[
        selector
      ];
    },
    querySelectorAll(selector) {
      if (selector === '[data-person-row]') return rows;
      return inputs.filter(
        (input) => !input.disabled && (!selector.includes(':checked') || input.checked),
      );
    },
  };
}

test('bulk selection includes only explicit checked eligible targets', () => {
  const root = directory([
    { value: '1', checked: true, disabled: true },
    { value: '2', checked: true },
    { value: '3', checked: false },
  ]);
  assert.deepEqual(selectedTargets(root), ['2']);
  assert.equal(syncSelection(root), 1);
  assert.equal(root.all.indeterminate, true);
  assert.equal(root.all.checked, false);
  assert.equal(root.bar.hidden, false);
  assert.match(root.count.textContent, /1/);
});

test('select-all state excludes disabled self and empty pages cannot select all', () => {
  const full = directory([
    { value: '1', disabled: true },
    { value: '2', checked: true },
  ]);
  syncSelection(full);
  assert.equal(full.all.checked, true);
  assert.equal(full.all.indeterminate, false);
  const empty = directory([]);
  syncSelection(empty);
  assert.equal(empty.all.checked, false);
  assert.equal(empty.all.disabled, true);
  assert.equal(empty.bar.hidden, true);
});

test('changing result pages clears the mass-update bar rather than retaining invisible targets', () => {
  const first = directory([{ value: '1', checked: true }]);
  syncSelection(first);
  assert.equal(first.bar.hidden, false);
  const next = directory([{ value: '2', checked: false }]);
  syncSelection(next);
  assert.deepEqual(selectedTargets(next), []);
  assert.equal(next.bar.hidden, true);
});

test('the compact role summary keeps multiple roles and clears without inserting HTML', () => {
  const summary = { dataset: { empty: 'Unassigned' } };
  let labels = ['Member', '<img src=x onerror=alert(1)>'];
  const row = {
    querySelector: () => summary,
    querySelectorAll: () => labels.map((textContent) => ({ closest: () => ({ textContent }) })),
  };
  syncRoleSummary(row);
  assert.equal(summary.textContent, 'Member, <img src=x onerror=alert(1)>');
  labels = [];
  syncRoleSummary(row);
  assert.equal(summary.textContent, 'Unassigned');
});

test('a person editor opens inside any active native dialog without a fixed settings ID', async () => {
  const source = await readFile(
    new URL('../../src/Resources/public/assets/people.js', import.meta.url),
    'utf8',
  );
  const listeners = {};
  const editor = { addEventListener() {} };
  const content = {
    replaceChildren(...nodes) {
      this.nodes = nodes;
    },
  };
  const error = {};
  const parent = { id: 'settings-detail-7', open: true };
  const root = directory([]);
  const originalQuery = root.querySelector;
  root.querySelector = (selector) =>
    ({
      '[data-people-error]': error,
      '#people-editor': editor,
      '[data-person-content]': content,
      '[data-people-search]': { addEventListener() {} },
    })[selector] || originalQuery(selector);
  root.closest = (selector) => (selector === 'dialog' ? parent : null);
  root.addEventListener = (type, handler) => {
    listeners[type] = handler;
  };
  root.ownerDocument = { dispatchEvent() {} };
  const person = {
    dataset: { personOpen: '/settings/users/2' },
    closest: (selector) => (selector === '[data-person-open]' ? person : null),
    setAttribute() {},
    removeAttribute() {},
  };
  const fragment = { textContent: 'Person form' };
  const opened = [];
  const context = {
    t: (message) => message,
    fetch: async () => ({ ok: true, text: async () => '<form>Person form</form>' }),
    CustomEvent: class {},
    DOMParser: class {
      parseFromString() {
        return { querySelector: () => ({}), body: { childNodes: [fragment] } };
      }
    },
    openDialog: (dialog) => opened.push(dialog),
  };
  runInNewContext(
    source.replace(/^import .*;\n/gm, '').replaceAll('export function', 'function'),
    context,
  );
  context.mountPeopleDirectory(root);
  await listeners.click({ target: person });
  assert.deepEqual(opened, [editor]);
  assert.deepEqual(content.nodes, [fragment]);
  assert.equal(error.textContent, '');
  parent.open = false;
  await listeners.click({ target: person });
  assert.equal(opened.length, 1, 'the plain page keeps its existing inline person form');
});
