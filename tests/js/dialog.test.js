import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = await readFile(
  new URL('../../src/Resources/public/assets/dialog.js', import.meta.url),
  'utf8',
);
const settle = () => new Promise(setImmediate);

function fixture({ reduced = false, hidden = false, drawer = false } = {}) {
  const animations = [];
  const timers = new Set();
  const closed = [];
  let opens = 0;
  const style = {
    transform: 'none',
    opacity: '1',
    borderRadius: '20px',
    getPropertyValue(name) {
      return {
        '--dialog-enter-duration': '480ms',
        '--dialog-exit-duration': '280ms',
        '--dialog-enter-easing': 'cubic-bezier(0.16, 1, 0.3, 1)',
        '--dialog-exit-easing': 'cubic-bezier(0.4, 0, 0.6, 1)',
        '--dialog-closed-transform': drawer ? 'translateX(100%)' : 'translateY(16px) scale(0.98)',
        '--dialog-closed-opacity': drawer ? '1' : '0',
      }[name];
    },
  };
  const dialog = {
    open: false,
    dataset: {},
    showModal() {
      this.open = true;
      opens += 1;
    },
    close(value) {
      this.open = false;
      closed.push(value);
    },
    animate(frames, options) {
      let resolve, reject;
      const run = {
        frames,
        options,
        cancelled: false,
        finished: new Promise((done, fail) => {
          resolve = done;
          reject = fail;
        }),
        finish: () => resolve(),
        cancel() {
          this.cancelled = true;
          reject();
        },
      };
      animations.push(run);
      return run;
    },
  };
  const context = {
    getComputedStyle: () => style,
    matchMedia: () => ({ matches: reduced }),
    document: { hidden },
    requestAnimationFrame: (callback) => callback(),
    setTimeout: (callback) => {
      timers.add(callback);
      return callback;
    },
    clearTimeout: (callback) => timers.delete(callback),
  };
  runInNewContext(source.replaceAll('export function', 'function'), context);
  return {
    dialog,
    style,
    animations,
    closed,
    context,
    opens: () => opens,
    open: (options) => context.openDialog(dialog, options),
    close: (options) => context.closeDialog(dialog, options),
    expire: () => [...timers].forEach((callback) => callback()),
  };
}

test('drawer returns to the right edge before native close and cleanup', async () => {
  const ui = fixture({ drawer: true });
  const enter = ui.open();
  assert.equal(ui.animations[0].options.duration, 480);
  ui.animations[0].finish();
  await enter;
  const exit = ui.close({ returnValue: 'discard' });
  assert.equal(ui.dialog.open, true, 'native top layer must remain during the exit');
  assert.deepEqual(ui.closed, []);
  assert.equal(ui.animations[1].frames[1].transform, 'translateX(100%)');
  assert.equal(ui.animations[1].frames[1].opacity, '1', 'drawer content must not disappear');
  assert.equal(ui.animations[1].options.duration, 280);
  ui.animations[1].finish();
  await exit;
  assert.equal(ui.dialog.open, false);
  assert.deepEqual(ui.closed, ['discard']);
  assert.equal('dialogClosing' in ui.dialog.dataset, false);
});

test('closing during entry continues from the visible position and only closes once', async () => {
  const ui = fixture();
  const entering = ui.open();
  ui.style.transform = 'matrix(1, 0, 0, 1, 0, 8)';
  ui.style.opacity = '0.6';
  const closing = ui.close();
  assert.equal(ui.close(), closing);
  await ui.open();
  assert.equal(ui.opens(), 1, 'opening during exit must not start another native dialog');
  assert.equal(ui.animations[0].cancelled, true);
  assert.equal(ui.animations[1].frames[0].transform, ui.style.transform);
  assert.equal(ui.animations[1].frames[0].opacity, '0.6');
  ui.animations[1].finish();
  await Promise.all([entering, closing]);
  assert.equal(ui.closed.length, 1);
});

test('settings can return to the same card with the shared timing', async () => {
  const ui = fixture();
  const card = {
    transform: 'translate(-40px, 70px) scale(.3, .2)',
    opacity: 1,
    borderRadius: '24px',
  };
  const entering = ui.open({ from: () => card });
  assert.equal(ui.animations[0].frames[0], card);
  ui.animations[0].finish();
  await entering;
  const closing = ui.close({ to: card });
  assert.equal(ui.animations[1].frames[1], card);
  assert.equal(ui.animations[1].options.duration, 280);
  ui.animations[1].finish();
  await closing;
});

test('reduced motion and hidden tabs close immediately without an animation', async () => {
  for (const preferences of [{ reduced: true }, { hidden: true }]) {
    const ui = fixture(preferences);
    await ui.open();
    await ui.close({ returnValue: 'yes' });
    assert.equal(ui.animations.length, 0);
    assert.deepEqual(ui.closed, ['yes']);
  }
});

test('a frozen or cancelled exit cannot strand the native modal', async () => {
  for (const cancel of [false, true]) {
    const ui = fixture();
    await ui.open({ animate: false });
    const closing = ui.close();
    if (cancel) ui.animations[0].cancel();
    else ui.expire();
    await settle();
    await closing;
    assert.equal(ui.dialog.open, false);
    assert.equal(ui.closed.length, 1);
  }
});
