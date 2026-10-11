import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mountAdvancedSettings } from '../../src/Resources/public/assets/settings-configuration.js';

function setup(saved = null) {
  const listeners = {};
  const attributes = {};
  const toggle = {
    checked: false,
    dataset: { settingsPreference: 'nafinity.settings.advanced.7' },
    setAttribute: (key, value) => (attributes[key] = value),
    addEventListener: (name, callback) => (listeners[name] = callback),
  };
  const group = { hidden: true };
  const fields = { hidden: true, disabled: true, value: 'unsaved override' };
  const values = new Map([[toggle.dataset.settingsPreference, saved]]);
  const storage = {
    getItem: (key) => values.get(key),
    setItem: (key, value) => values.set(key, value),
  };
  const root = {
    querySelector: () => toggle,
    querySelectorAll: (selector) =>
      selector === '[data-settings-advanced-group]' ? [group] : [fields],
  };
  return { root, storage, toggle, group, fields, values, attributes, listeners };
}

test('simple mode hides advanced cards and prevents hidden field submission', () => {
  const state = setup();
  mountAdvancedSettings(state.root, state.storage);
  assert.equal(state.toggle.checked, false);
  assert.equal(state.group.hidden, true);
  assert.equal(state.fields.hidden, true);
  assert.equal(state.fields.disabled, true);
  assert.equal(state.attributes['aria-expanded'], 'false');
});

test('switching both ways preserves edits and saves the per-administrator view preference', () => {
  const state = setup();
  mountAdvancedSettings(state.root, state.storage);
  state.toggle.checked = true;
  state.listeners.change();
  assert.equal(state.group.hidden, false);
  assert.equal(state.fields.hidden, false);
  assert.equal(state.fields.disabled, false);
  assert.equal(state.attributes['aria-expanded'], 'true');
  assert.equal(state.values.get(state.toggle.dataset.settingsPreference), '1');
  state.toggle.checked = false;
  state.listeners.change();
  assert.equal(state.fields.disabled, true);
  assert.equal(state.fields.hidden, true);
  assert.equal(state.fields.value, 'unsaved override');
  assert.equal(state.values.get(state.toggle.dataset.settingsPreference), '0');
});

test('advanced mode survives a form reload within the browser tab', () => {
  const state = setup('1');
  mountAdvancedSettings(state.root, state.storage);
  assert.equal(state.toggle.checked, true);
  assert.equal(state.fields.disabled, false);
  assert.equal(state.group.hidden, false);
});

test('a direct link can reveal an advanced card before the modal opens', () => {
  const state = setup();
  const advanced = mountAdvancedSettings(state.root, state.storage);
  advanced.show();
  assert.equal(state.toggle.checked, true);
  assert.equal(state.group.hidden, false);
  assert.equal(state.fields.disabled, false);
});

test('unavailable storage still allows the switch to operate', () => {
  const state = setup();
  const storage = {
    getItem() {
      throw new Error('storage blocked');
    },
    setItem() {
      throw new Error('storage blocked');
    },
  };
  const advanced = mountAdvancedSettings(state.root, storage);
  assert.equal(state.fields.disabled, true);
  advanced.show();
  assert.equal(state.fields.disabled, false);
});

test('personal and project pages need no advanced mode', () => {
  assert.equal(mountAdvancedSettings({ querySelector: () => null }), null);
});
