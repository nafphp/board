import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const assets = new URL('../../src/Resources/public/assets/', import.meta.url);
const source = await readFile(new URL('ticket.js', assets), 'utf8');
const body = (start, end) => source.slice(source.indexOf(start), source.indexOf(end));

test('every ticket entry uses one ES module identity and one editor/write queue', async () => {
  const layout = await readFile(new URL('../../src/views/layout.phtml', import.meta.url), 'utf8');
  const scripts = await Promise.all(
    (await readdir(assets))
      .filter((name) => name.endsWith('.js'))
      .map((name) => readFile(new URL(name, assets), 'utf8')),
  );
  const urls = [layout, ...scripts].flatMap((text) =>
    [...text.matchAll(/['"]((?:\.\/|\/assets\/)ticket\.js[^'"]*)['"]/g)].map(
      (match) => new URL(match[1], 'https://board.test/assets/').href,
    ),
  );
  assert.ok(urls.length > 0, 'the ticket runtime is not loaded');
  assert.equal(
    new Set(urls).size,
    1,
    'different query versions create independent editor registries and write queues',
  );
});

test('mounting a draft leaves description and metadata collapsed and never eagerly loads Quill', () => {
  const opened = [];
  let initialized = 0;
  const makeField = (prose = null) => {
    const editor = { hidden: false };
    const display = { hidden: true };
    return {
      editor,
      display,
      prose,
      querySelector: (selector) =>
        selector === '[data-field-editor]'
          ? editor
          : selector === '.inline-display'
            ? display
            : prose,
    };
  };
  const title = makeField();
  const description = makeField({ hidden: true });
  const date = makeField();
  const form = {
    dataset: {},
    elements: { title: { closest: () => title } },
    querySelectorAll: () => [title, description, date],
  };
  const context = {
    document: { querySelectorAll: () => [form] },
    enhanceChoices() {},
    beginEdit: (field) => opened.push(field),
    resizeTitle() {},
    initializeEditor() {
      initialized += 1;
      return Promise.resolve();
    },
  };
  const code = body('function initializeCreation()', "window.addEventListener('resize'");
  runInNewContext(code + '\ninitializeCreation();', context);
  assert.equal(initialized, 0);
  assert.deepEqual(opened, [title]);
  for (const field of [description, date]) {
    assert.equal(field.editor.hidden, true);
    assert.equal(field.display.hidden, false);
  }
  assert.equal(description.prose.hidden, false);
  description.editor.hidden = false;
  runInNewContext('initializeCreation();', context);
  assert.equal(
    description.editor.hidden,
    false,
    'a second fragment notification reset the open draft',
  );
  assert.equal(opened.length, 1);
});

test('simultaneous first opens construct exactly one rich editor', async () => {
  let resolve;
  const pending = new Promise((done) => {
    resolve = done;
  });
  let constructed = 0;
  const sourceControl = { name: 'description', value: 'Saved draft' };
  const editor = {
    setText(value) {
      assert.equal(value, 'Saved draft');
    },
    history: { clear() {} },
    root: { setAttribute() {} },
    on() {},
  };
  const form = {
    closest: () => ({}),
    isConnected: true,
    querySelectorAll: () => [],
    querySelector: () => null,
  };
  const container = {
    closest: () => form,
    querySelector: (selector) => (selector === '[data-rich-editor]' ? {} : sourceControl),
  };
  const context = {
    editors: new WeakMap(),
    saving: new WeakMap(),
    inlineEdits: new WeakMap(),
    t: (message) => message,
    loadQuill: () => pending,
  };
  runInNewContext(body('async function initializeEditor(', 'function resizeTitle('), context);
  const first = context.initializeEditor(container);
  const second = context.initializeEditor(container);
  resolve(function Quill() {
    constructed += 1;
    return editor;
  });
  assert.equal(await first, editor);
  assert.equal(await second, editor);
  assert.equal(await context.initializeEditor(container), editor);
  assert.equal(constructed, 1);
  assert.equal(sourceControl.name, 'description_html');
  assert.equal(sourceControl.hidden, true);
});

function draftFixture() {
  const input = { name: 'due_date', type: 'date', value: '2026-10-12' };
  const otherInput = { name: 'title', value: 'Keep this title' };
  const workspace = { dataset: {} };
  const owner = { elements: [input, otherInput] };
  const attributes = {};
  const display = {
    setAttribute: (key, value) => {
      attributes[key] = value;
    },
    focus() {},
  };
  const classes = new Set(['is-editing']);
  const container = {
    dataset: {},
    isConnected: true,
    hidden: false,
    matches: () => false,
    closest: () => owner,
    contains: () => false,
    hasAttribute: () => false,
    querySelector: () => null,
    querySelectorAll: () => [input],
    cloneNode: () => {
      const saved = { ...input };
      return { querySelectorAll: () => [saved] };
    },
  };
  const field = {
    classList: { contains: (name) => classes.has(name), remove: (name) => classes.delete(name) },
    closest: () => workspace,
    querySelector: (selector) => (selector === '[data-field-editor]' ? container : display),
  };
  let requests = 0;
  let previews = 0;
  const context = {
    saving: new WeakMap(),
    editors: new WeakMap(),
    inlineEdits: new WeakMap(),
    revisionFields: new Set(),
    clearTimeout() {},
    t: (message) => message,
    inlineStatus() {},
    closeChoices() {},
    enhanceChoices() {},
    document: { activeElement: null },
    renderDraftDisplay: () => {
      previews += 1;
    },
    flushInline: () => {
      requests += 1;
      throw new Error('a draft tried to auto-save');
    },
  };
  runInNewContext(
    body('function inlineState(', 'function inlineStatus(') +
      body('async function finishEdit(', 'function loadQuill(') +
      body('function cancelEdit(', 'export async function refresh('),
    context,
  );
  context.inlineState(container);
  return {
    context,
    input,
    otherInput,
    container,
    field,
    attributes,
    requests: () => requests,
    previews: () => previews,
  };
}

test('leaving a draft field keeps its new value locally without writing a ticket', async () => {
  const ui = draftFixture();
  ui.input.value = '2026-10-15';
  assert.equal(await ui.context.finishEdit(ui.field), true);
  assert.equal(ui.input.value, '2026-10-15');
  assert.equal(ui.container.hidden, true);
  assert.equal(ui.field.classList.contains('is-editing'), false);
  assert.equal(ui.attributes['aria-expanded'], 'false');
  assert.equal(ui.requests(), 0);
  assert.equal(ui.previews(), 1);
});

test('Escape restores only the current field, preserving the other creation inputs', () => {
  const ui = draftFixture();
  ui.input.value = '2026-10-15';
  ui.otherInput.value = 'A separately edited title';
  ui.context.cancelEdit(ui.field);
  assert.equal(ui.input.value, '2026-10-12');
  assert.equal(ui.otherInput.value, 'A separately edited title');
  assert.equal(ui.container.hidden, true);
  assert.equal(ui.requests(), 0);
});

test('focus moving within an editor does not confirm a draft or replace its Escape snapshot', async () => {
  const ui = draftFixture();
  ui.input.value = '2026-10-15';
  ui.container.contains = () => true;
  assert.equal(await ui.context.finishEdit(ui.field, true), false);
  assert.equal(ui.previews(), 0);
  ui.context.cancelEdit(ui.field);
  assert.equal(ui.input.value, '2026-10-12');
});
