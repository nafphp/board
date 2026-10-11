import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = await readFile(
  new URL('../../src/Resources/public/assets/ticket.js', import.meta.url),
  'utf8',
);
const clickSource = source.slice(
  source.indexOf("document.addEventListener('click',"),
  source.indexOf('/*\n * "Mir zuweisen"'),
);
const keySource = source.slice(
  source.indexOf("document.addEventListener('keydown',"),
  source.indexOf("document.addEventListener('submit',"),
);

function fixture(editable = true) {
  const opened = [];
  const listeners = {};
  const field = {
    querySelector: () => (editable ? trigger : null),
  };
  const trigger = {
    closest: (selector) => (selector === '[data-inline-field]' ? field : null),
  };
  const prose = {
    closest: (selector) => (selector === '[data-inline-field]' ? field : null),
  };
  function target({ link = false, button = false } = {}) {
    return {
      closest(selector) {
        for (const part of selector.split(',').map((part) => part.trim())) {
          if (part === 'a' && link) return this;
          if (part === '.inline-display[role=button]' && button) return trigger;
          if (part === '[data-description-display]' && !button) return prose;
        }
        return null;
      },
      matches: () => false,
    };
  }
  runInNewContext(clickSource + keySource, {
    document: {
      addEventListener: (name, listener) => (listeners[name] = listener),
      querySelectorAll: () => [],
    },
    beginEdit: (value) => opened.push(value),
  });
  return {
    opened,
    field,
    click(options = {}, defaultPrevented = false) {
      listeners.click({ target: target(options), defaultPrevented });
    },
    key(key) {
      let prevented = false;
      listeners.keydown({
        target: target({ button: true }),
        key,
        preventDefault: () => (prevented = true),
      });
      return prevented;
    },
  };
}

test('clicking description content or its empty placeholder opens the existing inline editor', () => {
  const ui = fixture();
  ui.click();
  assert.deepEqual(ui.opened, [ui.field]);
});

test('description links keep their native navigation, including clicks on nested link text', () => {
  const ui = fixture();
  ui.click({ link: true });
  assert.deepEqual(ui.opened, []);
});

test('read-only descriptions never open an editor', () => {
  const ui = fixture(false);
  ui.click();
  assert.deepEqual(ui.opened, []);
});

test('a click already handled by another control does not open the description', () => {
  const ui = fixture();
  ui.click({}, true);
  assert.deepEqual(ui.opened, []);
});

test('the title and keyboard edit trigger still open the same editor', () => {
  const ui = fixture();
  ui.click({ button: true });
  assert.equal(ui.key('Enter'), true);
  assert.equal(ui.key(' '), true);
  assert.deepEqual(ui.opened, [ui.field, ui.field, ui.field]);
});
