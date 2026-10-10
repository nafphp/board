import assert from 'node:assert/strict';
import { test } from 'node:test';
import { selectedTargets, syncSelection } from '../../src/Resources/public/assets/people.js';

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
