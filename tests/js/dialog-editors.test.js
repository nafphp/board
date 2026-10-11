import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const disclosure = await readFile(
  new URL('../../src/Resources/public/assets/disclosure.js', import.meta.url),
  'utf8',
);
const source = await readFile(
  new URL('../../src/Resources/public/assets/dialog.js', import.meta.url),
  'utf8',
);

function node(name) {
  return {
    name,
    childNodes: [],
    dataset: {},
    attributes: {},
    listeners: {},
    append(...nodes) {
      for (const child of nodes) {
        child.remove?.();
        child.parent = this;
        this.childNodes.push(child);
      }
    },
    remove() {
      if (this.parent)
        this.parent.childNodes = this.parent.childNodes.filter((child) => child !== this);
      this.parent = null;
    },
    after(child) {
      this.parent.append(child);
    },
    setAttribute(name, value) {
      this.attributes[name] = value;
    },
    addEventListener(type, callback) {
      (this.listeners[type] ??= []).push(callback);
    },
    fire(type, event) {
      for (const callback of this.listeners[type] || []) callback(event);
    },
    closest() {
      return null;
    },
  };
}

function fixture() {
  const summary = node('summary');
  const label = { textContent: 'Editors <script>' };
  summary.querySelector = () => label;
  const form = node('form');
  form.value = 'unsaved';
  form.submit = () => 'original handler';
  const notice = node('notice');
  const details = node('details');
  details.querySelector = () => summary;
  details.append(summary, notice, form);
  const group = node('group');
  group.dataset.dialogEditors = 'details';
  group.querySelectorAll = () => [details];
  group.append(details);
  const clones = [];
  const template = {
    content: {
      firstElementChild: {
        cloneNode() {
          const dialog = node('dialog');
          dialog.title = node('title');
          dialog.body = node('body');
          dialog.querySelector = (selector) =>
            selector === '[data-dialog-title]' ? dialog.title : dialog.body;
          clones.push(dialog);
          return dialog;
        },
      },
    },
  };
  const document = { getElementById: () => template, querySelectorAll: () => [group] };
  const context = {};
  runInNewContext(
    source.replace(/^import .*;\n/gm, '').replaceAll('export function', 'function'),
    context,
  );
  const opened = [];
  context.openDialog = (dialog) => {
    dialog.open = true;
    opened.push(dialog);
    return Promise.resolve();
  };
  return { context, document, summary, details, form, notice, group, clones, opened };
}

test('a disclosure editor moves the original form and restores its draft and handlers on close', async () => {
  const ui = fixture();
  ui.context.mountDialogEditors(ui.document);
  assert.equal(ui.details.open, false);
  assert.equal(ui.summary.attributes['aria-haspopup'], 'dialog');
  const event = {
    target: ui.summary,
    defaultPrevented: false,
    preventDefault() {
      this.defaultPrevented = true;
    },
  };
  ui.summary.fire('click', event);
  assert.equal(event.defaultPrevented, true);
  // The shared disclosure animator sees the same bubbling click after the editor.
  // It must respect the handled event instead of opening an empty inline disclosure.
  let animateDisclosure;
  runInNewContext(disclosure, {
    matchMedia: () => ({ matches: false }),
    document: {
      addEventListener(type, listener) {
        if (type === 'click') animateDisclosure = listener;
      },
    },
  });
  ui.details.tagName = 'DETAILS';
  ui.summary.parentElement = ui.details;
  ui.summary.closest = (selector) => (selector === 'summary' ? ui.summary : null);
  animateDisclosure(event);
  assert.equal(ui.details.open, false);
  const dialog = ui.opened[0];
  assert.equal(dialog.title.textContent, 'Editors <script>');
  assert.deepEqual(dialog.body.childNodes, [ui.notice, ui.form]);
  assert.equal(ui.form.submit(), 'original handler');
  ui.form.value = 'changed in dialog';
  dialog.open = false;
  dialog.fire('close');
  assert.deepEqual(ui.details.childNodes, [ui.summary, ui.notice, ui.form]);
  assert.equal(ui.form.value, 'changed in dialog');
  assert.equal(ui.summary.attributes['aria-expanded'], 'false');
  assert.equal(dialog.parent, null);
  await ui.context.openDialogEditor(ui.details);
  assert.equal(ui.opened[1], dialog);
  assert.equal(dialog.body.childNodes[1], ui.form);
});

test('mounting twice binds once, ignores nested controls, and uses a fresh explicit title', async () => {
  const ui = fixture();
  ui.context.mountDialogEditors(ui.document);
  ui.context.mountDialogEditors(ui.document);
  assert.equal(ui.clones.length, 1);
  ui.summary.fire('click', {
    target: { closest: () => ({}) },
    preventDefault() {
      assert.fail();
    },
  });
  assert.equal(ui.opened.length, 0);
  ui.details.dataset.dialogTitle = 'Theme · Current draft';
  await ui.context.openDialogEditor(ui.details);
  await ui.context.openDialogEditor(ui.details);
  assert.equal(ui.opened.length, 1);
  assert.equal(ui.opened[0].title.textContent, 'Theme · Current draft');
  assert.equal(ui.opened[0].attributes['aria-labelledby'], ui.opened[0].title.id);
});

test('missing shared shell leaves native disclosures unchanged', () => {
  const ui = fixture();
  ui.document.getElementById = () => null;
  ui.details.open = true;
  ui.context.mountDialogEditors(ui.document);
  assert.equal(ui.details.open, true);
  assert.equal(ui.summary.listeners.click, undefined);
});
