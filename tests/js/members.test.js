import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mountAccountSearch } from '../../src/Resources/public/assets/members.js';

function setup(context) {
  context.mock.timers.enable({ apis: ['setTimeout'] });
  const list = {
    children: [],
    replaceChildren() {
      this.children = [];
    },
    append(option) {
      this.children.push(option);
    },
  };
  const status = { textContent: '' };
  const requests = [];
  const document = {
    baseURI: 'https://board.example/projects/42/settings',
    getElementById: () => list,
    createElement: () => ({}),
  };
  let onInput;
  const input = {
    value: '',
    ownerDocument: document,
    dataset: { accountSearch: '/projects/42/accounts' },
    getAttribute: () => 'accounts',
    closest: () => ({ querySelector: () => status }),
    addEventListener: (event, listener) => {
      onInput = listener;
    },
  };
  mountAccountSearch(input, {
    fetcher: (url, options) =>
      new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  });
  return {
    list,
    status,
    requests,
    type(value) {
      input.value = value;
      onInput();
    },
    tick() {
      context.mock.timers.tick(250);
    },
    async result(index, accounts, ok = true) {
      requests[index].resolve({ ok, json: async () => ({ accounts }) });
      await new Promise(setImmediate);
    },
  };
}

test('autocomplete debounces a name search and offers the selected email without interpreting markup', async (context) => {
  const ui = setup(context);
  ui.type('a');
  ui.tick();
  assert.equal(ui.requests.length, 0);
  ui.type('Al');
  ui.type('Alice');
  ui.tick();
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].url.pathname, '/projects/42/accounts');
  assert.equal(ui.requests[0].url.searchParams.get('q'), 'Alice');
  assert.equal(ui.requests[0].options.headers.Accept, 'application/json');
  await ui.result(0, [{ name: '<img src=x>', email: 'alice@example.test' }]);
  assert.deepEqual(ui.list.children, [{ value: 'alice@example.test', label: '<img src=x>' }]);
});

test('a changed query clears suggestions, cancels the previous request and ignores late results', async (context) => {
  const ui = setup(context);
  ui.type('Al');
  ui.tick();
  ui.type('Bob');
  ui.tick();
  assert.equal(ui.requests[0].options.signal.aborted, true);
  await ui.result(1, [{ name: 'Bob', email: 'bob@example.test' }]);
  await ui.result(0, [{ name: 'Alice', email: 'alice@example.test' }]);
  assert.deepEqual(ui.list.children, [{ value: 'bob@example.test', label: 'Bob' }]);
  ui.type('b');
  ui.tick();
  assert.equal(ui.list.children.length, 0);
  assert.equal(ui.status.textContent, '');
  assert.equal(ui.requests.length, 2);
});

test('an empty result points to the invitation form', async (context) => {
  const ui = setup(context);
  ui.type('Nobody');
  ui.tick();
  await ui.result(0, []);
  assert.match(ui.status.textContent, /unten einladen/);
});

test('a denied search leaves the direct email fallback available and a later search can succeed', async (context) => {
  const ui = setup(context);
  ui.type('Alice');
  ui.tick();
  await ui.result(0, [], false);
  assert.match(ui.status.textContent, /vollständige E-Mail-Adresse/);
  ui.type('Bob');
  ui.tick();
  await ui.result(1, [{ name: 'Bob', email: 'bob@example.test' }]);
  assert.equal(ui.status.textContent, '');
  assert.equal(ui.list.children[0].value, 'bob@example.test');
});
