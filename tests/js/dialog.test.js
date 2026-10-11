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
    translate: 'none',
    height: '400px',
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
  const dialogs = [];
  function element(tag) {
    const attributes = new Map();
    return {
      tag,
      children: [],
      style: {},
      setAttribute: (key, value) => attributes.set(key, value),
      getAttribute: (key) => attributes.get(key) ?? null,
      append(...children) {
        for (const child of children) {
          this.children.push(child);
          child.parent = this;
        }
      },
      remove() {
        if (this.parent)
          this.parent.children = this.parent.children.filter((child) => child !== this);
        this.parent = null;
      },
      showPopover() {
        this.shown = true;
      },
      getBoundingClientRect() {
        return { height: this.children.length * 40 };
      },
      querySelectorAll() {
        return this.children.filter((child) => child.tag === 'button');
      },
    };
  }
  function newDialog() {
    const attributes = new Map();
    const listeners = new Map();
    const properties = new Map();
    const dialog = {
      ...element('dialog'),
      querySelector: () => ({ textContent: `Dialog ${dialogs.indexOf(dialog) + 1}` }),
      getBoundingClientRect: () => ({ left: 100, top: 160, width: 600, height: 400 }),
      dispatchEvent(event) {
        this.fire(event.type, event);
        return !event.defaultPrevented;
      },
      open: false,
      inert: false,
      dataset: {},
      getAttribute: (key) => attributes.get(key) ?? null,
      setAttribute: (key, value) => attributes.set(key, value),
      removeAttribute: (key) => attributes.delete(key),
      toggleAttribute: (key, enabled) =>
        enabled ? attributes.set(key, '') : attributes.delete(key),
      style: {
        setProperty: (key, value) => properties.set(key, value),
        removeProperty: (key) => properties.delete(key),
      },
      depth: () => properties.get('--dialog-stack-depth'),
      addEventListener(type, callback, options) {
        const handlers = listeners.get(type) || [];
        handlers.push({ callback, once: options?.once });
        listeners.set(type, handlers);
      },
      removeEventListener(type, callback) {
        listeners.set(
          type,
          (listeners.get(type) || []).filter((handler) => handler.callback !== callback),
        );
      },
      fire(type, event = {}) {
        const handlers = [...(listeners.get(type) || [])];
        for (const handler of handlers) {
          if (handler.once) this.removeEventListener(type, handler.callback);
          handler.callback(event);
        }
      },
      showModal() {
        this.open = true;
        opens += 1;
      },
      close(value) {
        this.open = false;
        closed.push(value);
        this.fire('close');
      },
      animate(frames, options) {
        let resolve, reject;
        const run = {
          dialog: this,
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
    dialogs.push(dialog);
    return dialog;
  }
  const dialog = newDialog();
  const context = {
    getComputedStyle: (element) => ({
      ...style,
      transform:
        element.getAttribute('data-dialog-covered') !== null
          ? `translateY(${-40 * element.depth()}px)`
          : style.transform,
    }),
    matchMedia: () => ({ matches: reduced }),
    document: { hidden, createElement: element, getElementById: () => null },
    window: { addEventListener() {} },
    Event,
    t: (message, values = {}) => message.replace(':name', values.name || ''),
    requestAnimationFrame: (callback) => callback(),
    setTimeout: (callback) => {
      timers.add(callback);
      return callback;
    },
    clearTimeout: (callback) => timers.delete(callback),
  };
  runInNewContext(
    source.replace(/^import .*;\n/gm, '').replaceAll('export function', 'function'),
    context,
  );
  return {
    dialog,
    newDialog,
    controls: () =>
      dialogs
        .flatMap((item) => item.children)
        .find((item) => item.className === 'dialog-stack-controls'),
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

test('arbitrary dialog depth suspends only parents and restores their original accessibility state', async () => {
  const ui = fixture({ reduced: true });
  const dialogs = [ui.dialog, ...Array.from({ length: 6 }, ui.newDialog)];
  dialogs[0].setAttribute('aria-hidden', 'false');
  const focus = { isConnected: true, closest: () => null, focus: () => focus.count++, count: 0 };
  ui.context.document.activeElement = focus;
  for (const dialog of dialogs) await ui.context.openDialog(dialog);
  assert.equal(dialogs[0].depth(), 3, 'visual offset is bounded while logical depth is unlimited');
  for (const parent of dialogs.slice(0, -1)) {
    assert.equal(parent.inert, true);
    assert.equal(parent.getAttribute('aria-hidden'), 'true');
  }
  assert.equal(dialogs.at(-1).inert, false);
  for (let index = dialogs.length - 1; index >= 0; index--) {
    await ui.context.closeDialog(dialogs[index]);
    if (index) assert.equal(dialogs[index - 1].inert, false);
  }
  assert.equal(dialogs[0].getAttribute('aria-hidden'), 'false');
  assert.equal(focus.count, dialogs.length);
  assert.equal(ui.closed.length, dialogs.length);
});

test('closing a parent unwinds children before it and native close restores the stack too', async () => {
  const ui = fixture({ reduced: true });
  await ui.open();
  const child = ui.newDialog();
  await ui.context.openDialog(child);
  child.close('native');
  assert.equal(ui.dialog.inert, false);
  await ui.context.openDialog(child);
  await ui.close({ returnValue: 'parent' });
  assert.deepEqual(ui.closed, ['native', '', 'parent']);
  assert.equal(child.open, false);
  assert.equal(ui.dialog.open, false);
});

test('a dynamically added dialog gets animated Escape and respects an existing cancel guard', async () => {
  const ui = fixture({ reduced: true });
  const child = ui.newDialog();
  await ui.context.openDialog(child);
  const cancel = {
    defaultPrevented: false,
    preventDefault() {
      this.defaultPrevented = true;
    },
  };
  child.fire('cancel', cancel);
  assert.equal(child.open, false);
  const guarded = ui.newDialog();
  guarded.addEventListener('cancel', (event) => event.preventDefault());
  await ui.context.openDialog(guarded);
  cancel.defaultPrevented = false;
  guarded.fire('cancel', cancel);
  assert.equal(guarded.open, true);
  await ui.context.closeDialog(guarded);
});

test('named middle controls close their dialog and descendants while retaining older parents', async () => {
  const ui = fixture({ reduced: true });
  const middle = ui.newDialog();
  const child = ui.newDialog();
  await ui.open();
  await ui.context.openDialog(middle);
  await ui.context.openDialog(child);
  await settle();
  const buttons = ui.controls().children;
  assert.equal(buttons.length, 2);
  assert.equal(
    buttons[0].getAttribute('aria-label'),
    'Dialog 1 und darüberliegende Dialoge schließen',
  );
  assert.equal(buttons[1].children[0].textContent, 'Dialog 2');
  await buttons[1].onclick();
  await settle();
  assert.equal(child.open, false);
  assert.equal(middle.open, false);
  assert.equal(ui.dialog.open, true);
  assert.equal(ui.dialog.inert, false);
  assert.equal(ui.controls(), undefined);
});

test('the oldest header closes an arbitrarily deep stack and every header remains available', async () => {
  const ui = fixture({ reduced: true });
  const dialogs = [ui.dialog, ...Array.from({ length: 6 }, ui.newDialog)];
  for (const dialog of dialogs) await ui.context.openDialog(dialog);
  await settle();
  assert.equal(ui.controls().children.length, 6);
  await ui.controls().children[0].onclick();
  await settle();
  assert.equal(ui.closed.length, 7);
  assert.equal(
    dialogs.every((dialog) => !dialog.open),
    true,
  );
  assert.equal(ui.controls(), undefined);
});

test('a blocked child cancel stops a parent close request and can be retried', async () => {
  const ui = fixture({ reduced: true });
  const child = ui.newDialog();
  let blocked = true;
  child.addEventListener('cancel', (event) => {
    if (blocked) event.preventDefault();
  });
  await ui.open();
  await ui.context.openDialog(child);
  await settle();
  await ui.controls().children[0].onclick();
  assert.equal(ui.dialog.open, true);
  assert.equal(child.open, true);
  assert.equal(ui.controls().children[0].disabled, false);
  blocked = false;
  await ui.controls().children[0].onclick();
  assert.equal(child.open, false);
  assert.equal(ui.dialog.open, false);
});

test('stack requests await normal exit animations and ignore repeated clicks', async () => {
  const ui = fixture();
  const child = ui.newDialog();
  await ui.open({ animate: false });
  await ui.context.openDialog(child, { animate: false });
  ui.animations.forEach((animation) => animation.finish());
  await settle();
  const button = ui.controls().children[0];
  const first = button.onclick();
  await button.onclick();
  assert.equal(button.disabled, true);
  assert.equal(child.open, true);
  ui.animations.at(-1).finish();
  await settle();
  assert.equal(child.open, false);
  assert.equal(ui.dialog.open, true);
  ui.animations.at(-1).finish();
  await first;
  assert.equal(ui.closed.length, 2);
});

test('a native cancel handler can queue its close event before the cascade continues', async () => {
  const ui = fixture({ reduced: true });
  const child = ui.newDialog();
  let closedEvent;
  child.close = (value) => {
    child.open = false;
    ui.closed.push(value);
    closedEvent = () => child.fire('close');
  };
  child.addEventListener('cancel', () => child.close());
  await ui.open();
  await ui.context.openDialog(child);
  await settle();
  const closing = ui.controls().children[0].onclick();
  assert.equal(child.open, false);
  assert.equal(ui.dialog.open, true);
  closedEvent();
  await closing;
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.closed.length, 2);
});

test('a nested native modal stays accessible through its inert covered ancestor', async () => {
  const ui = fixture({ reduced: true });
  const child = ui.newDialog();
  ui.dialog.contains = (dialog) => dialog === child;
  await ui.open();
  await ui.context.openDialog(child);
  assert.equal(ui.dialog.inert, true);
  assert.equal(ui.dialog.getAttribute('aria-hidden'), null);
  assert.equal(child.inert, false);
  await ui.context.closeDialog(child);
  assert.equal(ui.dialog.inert, false);
  assert.equal(ui.dialog.getAttribute('aria-hidden'), null);
});
