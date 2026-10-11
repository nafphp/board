import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createSearchLoader } from '../../src/Resources/public/assets/workspace-search.js';

function setup(context) {
  context.mock.timers.enable({ apis: ['setTimeout'] });
  const states = [];
  const requests = [];
  const loader = createSearchLoader(
    'https://board.example/search/suggestions',
    (data) => states.push(data),
    {
      fetcher: (url, options) =>
        new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    },
  );
  return {
    loader,
    requests,
    states,
    tick: () => context.mock.timers.tick(180),
    async answer(index, data, overrides = {}) {
      requests[index].resolve({
        ok: true,
        redirected: false,
        json: async () => data,
        ...overrides,
      });
      await new Promise(setImmediate);
    },
  };
}

test('debounces queries and delivers dynamic board groups rather than fixed slots', async (context) => {
  const ui = setup(context);
  ui.loader.search('Work');
  ui.loader.search('Workspace');
  ui.tick();
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].url.searchParams.get('q'), 'Workspace');
  assert.equal(ui.requests[0].options.cache, 'no-store');
  const groups = [
    { project: { id: 8 }, tickets: [] },
    { project: { id: 42 }, tickets: [{ id: 7 }] },
  ];
  await ui.answer(0, { groups, html: '<section>escaped server markup</section>', count: 3 });
  assert.equal(ui.states.at(-1).state, 'ready');
  assert.deepEqual(ui.states.at(-1).groups, groups);
});

test('invalidates late responses when the user changes or clears the query', async (context) => {
  const ui = setup(context);
  ui.loader.search('Old');
  ui.tick();
  ui.loader.search('New');
  ui.tick();
  assert.equal(ui.requests[0].options.signal.aborted, true);
  await ui.answer(1, { count: 1, html: 'new' });
  await ui.answer(0, { count: 1, html: 'old' });
  assert.equal(ui.states.at(-1).html, 'new');
  ui.loader.search('Other');
  ui.tick();
  ui.loader.search('');
  await ui.answer(2, { count: 1, html: 'stale' });
  assert.equal(ui.states.at(-1).state, 'short');
});

test('closing the popup cancels pending timers and ignores already running answers', async (context) => {
  const ui = setup(context);
  ui.loader.search('One');
  ui.loader.cancel();
  ui.tick();
  assert.equal(ui.requests.length, 0);
  ui.loader.search('Two');
  ui.tick();
  ui.loader.cancel();
  const count = ui.states.length;
  await ui.answer(0, { count: 1, html: 'closed' });
  assert.equal(ui.states.length, count);
  assert.equal(ui.requests[0].options.signal.aborted, true);
});

test('counts Unicode characters and does not request empty or one-character terms', (context) => {
  const ui = setup(context);
  for (const query of [' ', 'x', '😀']) {
    ui.loader.search(query);
    ui.tick();
  }
  assert.equal(ui.requests.length, 0);
  ui.loader.search(' 😀😀 ');
  ui.tick();
  assert.equal(ui.requests[0].url.searchParams.get('q'), '😀😀');
});

test('refused and redirected answers show an error and the next query can recover', async (context) => {
  const ui = setup(context);
  ui.loader.search('Denied');
  ui.tick();
  await ui.answer(0, {}, { ok: false });
  assert.equal(ui.states.at(-1).state, 'error');
  ui.loader.search('Expired');
  ui.tick();
  await ui.answer(1, {}, { redirected: true });
  assert.equal(ui.states.at(-1).state, 'error');
  ui.loader.search('Empty');
  ui.tick();
  await ui.answer(2, { count: 0, html: '', groups: [] });
  assert.equal(ui.states.at(-1).state, 'ready');
  assert.equal(ui.states.at(-1).count, 0);
});
