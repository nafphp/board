import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const source = await readFile(
  new URL('../../src/Resources/public/assets/extensions.js', import.meta.url),
  'utf8',
);
// The real lifecycle module, with only its page-wide editor dependencies stubbed.
// No browser, fetch or DOM replacement is involved in these asynchronous races.
const isolated = source
  .replace(/import \{ t \} from [^;]+;/, 'const t = (message) => message;')
  .replace(/import \{ toast \} from [^;]+;/, 'const toast = () => {};')
  .replace(
    /import \{ refresh, saveTicket \} from [^;]+;/,
    'const refresh = () => {}; const saveTicket = () => {};',
  );
globalThis.document = { readyState: 'loading', addEventListener() {} };
const { mountWithin, disposeWithin } = await import(
  'data:text/javascript,' + encodeURIComponent(isolated)
);
delete globalThis.document;

const tick = () => new Promise((resolve) => setImmediate(resolve));
function root(mount) {
  return {
    dataset: { extensionModule: 'data:text/javascript,export const mount = root => root.begin();' },
    closest: () => null,
    querySelectorAll: () => [],
    begin: mount,
  };
}

test('closing a drawer during an asynchronous mount disposes its late result once', async () => {
  let started = false;
  let complete;
  let disposed = 0;
  const node = root(() => {
    started = true;
    return new Promise((resolve) => {
      complete = resolve;
    });
  });
  mountWithin(node);
  for (let attempt = 0; !started && attempt < 30; attempt += 1) await tick();
  assert.equal(started, true);
  disposeWithin(node);
  complete(() => {
    disposed += 1;
  });
  await tick();
  assert.equal(disposed, 1, 'a removed widget kept its timer/listener teardown');
  disposeWithin(node);
  assert.equal(disposed, 1);
});

test('repeated fragment notifications mount once and a replacement disposes once', async () => {
  let mounted = 0;
  let disposed = 0;
  const node = root(() => {
    mounted += 1;
    return () => {
      disposed += 1;
    };
  });
  mountWithin(node);
  mountWithin(node);
  await tick();
  assert.equal(mounted, 1);
  disposeWithin(node);
  disposeWithin(node);
  assert.equal(disposed, 1);
  mountWithin(node);
  await tick();
  assert.equal(mounted, 2);
  disposeWithin(node);
  assert.equal(disposed, 2);
});

test('a mount that fails after closing does not report into its removed node', async () => {
  let fail;
  const node = root(
    () =>
      new Promise((_resolve, reject) => {
        fail = reject;
      }),
  );
  mountWithin(node);
  await tick();
  assert.equal(typeof fail, 'function');
  disposeWithin(node);
  fail(new Error('delayed failure'));
  await tick();
});
