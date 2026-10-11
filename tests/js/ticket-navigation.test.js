import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const assets = new URL('../../src/Resources/public/assets/', import.meta.url);
const app = await readFile(new URL('app.js', assets), 'utf8');
const ticket = await readFile(new URL('ticket.js', assets), 'utf8');
const boardUrl = 'https://board.test/projects/1?priority=high#board';
const createUrl = 'https://board.test/projects/1/tickets/new';
const detailUrl = 'https://board.test/projects/1/tickets/NAF-3';

function drawerFixture() {
  const documentListeners = {};
  const windowListeners = {};
  const drawerListeners = {};
  const entries = [{ url: boardUrl, state: null }];
  const classes = new Set();
  const requests = [];
  let index = 0;
  let canClose = true;
  let workspace;
  const content = {
    set innerHTML(_html) {
      workspace = {
        dataset: {
          mode: classes.has('ticket-create-modal') ? 'create' : 'detail',
          ticketUrl: classes.has('ticket-create-modal') ? createUrl : detailUrl,
        },
      };
    },
    replaceChildren() {
      workspace = null;
    },
  };
  const drawer = {
    open: false,
    classList: {
      toggle(name, enabled) {
        if (enabled) classes.add(name);
        else classes.delete(name);
      },
    },
    setAttribute() {},
    querySelector: (selector) =>
      selector === '.drawer-content'
        ? content
        : selector === '.ticket-workspace'
          ? workspace
          : null,
    addEventListener: (name, callback) => (drawerListeners[name] = callback),
    dispatchEvent: () => canClose,
    showModal() {
      this.open = true;
    },
    close() {
      this.open = false;
      drawerListeners.close();
    },
  };
  const history = {
    get state() {
      return entries[index].state;
    },
    pushState(state, _title, url) {
      entries.splice(++index, entries.length, { state, url });
    },
    replaceState(state, _title, url) {
      entries[index] = { state, url };
    },
    back() {
      index -= 1;
      windowListeners.popstate();
    },
  };
  runInNewContext(
    app.slice(
      app.indexOf('const drawer ='),
      app.indexOf("document.addEventListener('keydown',", app.indexOf('const drawer =')),
    ),
    {
      URL,
      AbortController,
      Event,
      CustomEvent,
      t: (message) => message,
      toast() {},
      document: {
        querySelector: () => drawer,
        addEventListener(name, callback) {
          (documentListeners[name] ??= []).push(callback);
        },
        dispatchEvent() {},
      },
      window: { addEventListener: (name, callback) => (windowListeners[name] = callback) },
      location: {
        get href() {
          return entries[index].url;
        },
      },
      history,
      fetch: async (url) => {
        requests.push(url.href);
        return { ok: true, redirected: false, text: async () => '<div>workspace</div>' };
      },
    },
  );
  return {
    drawer,
    history,
    requests,
    url: () => entries[index].url,
    canClose: (value) => (canClose = value),
    async open(creating = true, modifiers = {}) {
      const link = {
        href: creating ? createUrl : detailUrl,
        hasAttribute: () => creating,
      };
      let prevented = false;
      await documentListeners.click[0]({
        target: { closest: () => link },
        button: 0,
        preventDefault: () => (prevented = true),
        ...modifiers,
      });
      return prevented;
    },
    created(refreshed = true) {
      if (refreshed) workspace.dataset.mode = 'detail';
      else workspace.dataset.needsReload = 'true';
      workspace.dataset.ticketUrl = detailUrl;
      history.replaceState(history.state, '', detailUrl);
    },
  };
}

test('creation keeps the exact board URL while fetching the existing creation fragment', async () => {
  const ui = drawerFixture();
  assert.equal(await ui.open(), true);
  assert.equal(ui.url(), boardUrl);
  assert.deepEqual(ui.requests, [createUrl + '?fragment=1']);
  assert.equal(ui.drawer.open, true);
  ui.drawer.close();
  assert.equal(ui.url(), boardUrl);
});

test('Back with a changed creation draft preserves the board address and the open drawer', async () => {
  const ui = drawerFixture();
  await ui.open();
  ui.canClose(false);
  ui.history.back();
  assert.equal(ui.url(), boardUrl);
  assert.equal(ui.drawer.open, true);
  ui.canClose(true);
  ui.history.back();
  assert.equal(ui.url(), boardUrl);
  assert.equal(ui.drawer.open, false);
});

test('saved tickets retain their deep URL, including after creation and a refused Back', async () => {
  for (const mode of ['detail', 'created', 'refresh-failed']) {
    const ui = drawerFixture();
    await ui.open(mode !== 'detail');
    if (mode !== 'detail') ui.created(mode !== 'refresh-failed');
    assert.equal(ui.url(), detailUrl);
    ui.canClose(false);
    ui.history.back();
    assert.equal(ui.url(), detailUrl);
    assert.equal(ui.drawer.open, true);
    ui.canClose(true);
    ui.drawer.close();
    assert.equal(ui.url(), boardUrl);
  }
});

test('modified clicks keep native new-tab navigation to the direct creation address', async () => {
  for (const modifiers of [{ ctrlKey: true }, { metaKey: true }, { button: 1 }]) {
    const ui = drawerFixture();
    assert.equal(await ui.open(true, modifiers), false);
    assert.equal(ui.drawer.open, false);
    assert.deepEqual(ui.requests, []);
  }
});

function draftFixture() {
  const editorRegistry = new WeakMap();
  const listeners = {};
  const controls = [
    { name: '_csrf', value: 'token' },
    { name: 'board_revision', value: '3' },
    { name: 'title', value: '' },
    { name: 'description', value: '' },
    { name: 'column_id', value: '1' },
    { name: 'assignee_ids[]', value: '' },
  ];
  const form = {
    dataset: {},
    matches: () => true,
    hasAttribute: (name) => name === 'data-ticket-create',
    cloneNode: () => ({}),
    elements: { description_html: controls[3] },
  };
  const scope = { querySelectorAll: () => [form] };
  const context = {
    editors: editorRegistry,
    inlineEdits: new WeakMap(),
    revisionFields: new Set(['_csrf', 'board_revision', 'version']),
    FormData: class {
      *[Symbol.iterator]() {
        for (const { name, value, disabled } of controls) if (!disabled) yield [name, value];
      }
    },
    document: scope,
    window: { addEventListener: (name, callback) => (listeners[name] = callback) },
  };
  runInNewContext(
    ticket.slice(ticket.indexOf('function values('), ticket.indexOf('function inlineStatus(')) +
      ticket.slice(ticket.indexOf('function dirty('), ticket.indexOf('function confirmClose(')),
    context,
  );
  context.inlineState(form);
  return {
    controls,
    form,
    context,
    warns() {
      let prevented = false;
      const event = { preventDefault: () => (prevented = true) };
      listeners.beforeunload(event);
      assert.equal(event.returnValue === '', prevented);
      return prevented;
    },
    richEditor(text, html) {
      controls[3].name = 'description_html';
      editorRegistry.set(form, { getText: () => text, getSemanticHTML: () => html });
    },
  };
}

test('reload warns for actual draft changes and stays silent after all values are restored', () => {
  const ui = draftFixture();
  assert.equal(ui.warns(), false);
  for (const index of [2, 4, 5]) {
    const original = ui.controls[index].value;
    ui.controls[index].value = 'changed';
    assert.equal(ui.warns(), true);
    ui.controls[index].value = original;
    assert.equal(ui.warns(), false);
  }
  ui.form.dataset.dirty = 'true';
  assert.equal(ui.warns(), false, 'a reverted field left a stale dirty flag');
  ui.controls[1].value = '4';
  assert.equal(ui.warns(), false, 'a revision-only update counted as a draft edit');
});

test('opening an empty rich editor is unchanged; rich text warns until cleared', () => {
  const ui = draftFixture();
  ui.richEditor('\n', '<p><br></p>');
  assert.equal(ui.warns(), false);
  ui.richEditor('A description\n', '<p><strong>A description</strong></p>');
  assert.equal(ui.warns(), true);
  ui.richEditor('\n', '<p><br></p>');
  assert.equal(ui.warns(), false);
});

test('confirmed creation and existing ticket comment drafts retain the shared unload guard', () => {
  const ui = draftFixture();
  ui.controls[2].value = 'Created ticket';
  assert.equal(ui.warns(), true);
  ui.context.inlineState(ui.form).baseline = ui.context.values(ui.form);
  assert.equal(ui.warns(), false);
  ui.form.hasAttribute = () => false;
  ui.form.dataset.dirty = 'true';
  assert.equal(ui.warns(), true);
  delete ui.form.dataset.dirty;
  assert.equal(ui.warns(), false);
});

test('an accepted creation with a failed detail refresh is saved after controls are enabled again', async () => {
  const ui = draftFixture();
  ui.controls[2].value = 'Accepted draft';
  const workspace = { dataset: {}, querySelector: () => ({ textContent: '' }) };
  Object.assign(ui.form, {
    closest: () => workspace,
    getAttribute: () => '/projects/1/tickets',
    querySelector: () => null,
    querySelectorAll: () => ui.controls,
  });
  let requests = 0;
  Object.assign(ui.context, {
    inlineStatus() {},
    error() {},
    t: (message) => message,
    CustomEvent,
    history: { replaceState() {} },
    fetch: async () =>
      ++requests === 1 ? { ok: true, json: async () => ({ url: detailUrl }) } : { ok: false },
  });
  ui.context.document.dispatchEvent = () => {};
  runInNewContext(
    ticket.slice(
      ticket.indexOf('async function performSave('),
      ticket.indexOf('function mentionOptions('),
    ),
    ui.context,
  );
  assert.equal(await ui.context.performSave(ui.form), false);
  assert.equal(workspace.dataset.ticketUrl, detailUrl);
  assert.equal(workspace.dataset.needsReload, 'true');
  assert.ok(ui.controls.every((control) => control.disabled === false));
  assert.equal(ui.warns(), false, 'the persisted draft still warned after a failed refresh');
  ui.controls[2].value = 'Another unsaved change';
  assert.equal(ui.warns(), true);
});
