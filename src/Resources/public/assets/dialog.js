import { t } from './i18n.js';

// One native top-layer stack for every caller, including declarative data-dialog buttons.
// A parent keeps its DOM, scroll position and focus until the last child returns.
const movement = new WeakMap();
const suspended = new WeakMap();
const entries = new WeakMap();
const stack = [];
let controls;
let revision = 0;
let requesting = false;

const editors = new WeakMap();
let editorSerial = 0;

// Enhance the original disclosure rather than duplicating its forms. Keeping
// the dialog beside the disclosure also preserves delegated feature listeners.
export function openDialogEditor(details) {
  const editor = editors.get(details);
  if (!editor || editor.dialog.open) return Promise.resolve();
  const { dialog, summary, body, title } = editor;
  title.textContent = details.dataset.dialogTitle || editor.name.textContent.trim();
  body.append(...[...details.childNodes].filter((node) => node !== summary));
  details.after(dialog);
  summary.setAttribute('aria-expanded', 'true');
  dialog.addEventListener(
    'close',
    () => {
      details.append(...body.childNodes);
      summary.setAttribute('aria-expanded', 'false');
      dialog.remove();
    },
    { once: true },
  );
  return openDialog(dialog);
}

export function mountDialogEditors(root = document) {
  const document = root.ownerDocument || root;
  const template = document.getElementById('settings-editor-template');
  if (!template) return;
  for (const group of root.querySelectorAll('[data-dialog-editors]')) {
    for (const details of group.querySelectorAll(group.dataset.dialogEditors || 'details')) {
      if (editors.has(details)) continue;
      const summary = details.querySelector(':scope > summary');
      if (!summary) continue;
      const dialog = template.content.firstElementChild.cloneNode(true);
      const title = dialog.querySelector('[data-dialog-title]');
      title.id = `dialog-editor-title-${++editorSerial}`;
      dialog.setAttribute('aria-labelledby', title.id);
      editors.set(details, {
        dialog,
        summary,
        title,
        body: dialog.querySelector('[data-dialog-body]'),
        name:
          summary.querySelector(group.dataset.dialogTitleSelector || '[data-dialog-title]') ||
          summary,
      });
      details.open = false;
      summary.setAttribute('aria-haspopup', 'dialog');
      summary.setAttribute('aria-expanded', 'false');
      summary.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.target.closest('a, button, input, select, textarea'))
          return;
        event.preventDefault();
        openDialogEditor(details);
      });
    }
  }
}

function positionControls() {
  if (!controls) return;
  const rect = stack.at(-1).getBoundingClientRect();
  controls.style.width = `${rect.width}px`;
  controls.style.left = `${rect.left}px`;
  controls.style.top = `max(var(--space-8), ${rect.top - controls.getBoundingClientRect().height}px)`;
}

function dialogTitle(dialog) {
  const labelled = (dialog.getAttribute('aria-labelledby') || '')
    .split(/\s+/)
    .map((id) => document.getElementById(id)?.textContent || '')
    .join(' ')
    .trim();
  return (
    labelled ||
    dialog.getAttribute('aria-label') ||
    dialog.querySelector('h1, h2, h3')?.textContent.trim() ||
    t('Dialog')
  );
}

// Ask each dialog through its normal cancel path. A dirty/busy guard stops the
// cascade, so these controls never bypass a feature's existing close decision.
async function requestStackClose(target) {
  if (requesting || !stack.includes(target)) return;
  requesting = true;
  controls?.querySelectorAll('button').forEach((button) => (button.disabled = true));
  try {
    while (stack.includes(target)) {
      const current = stack.at(-1);
      current.dispatchEvent(new Event('cancel', { cancelable: true }));
      if (!current.open) {
        // Native close() queues its close event; let it release the stack entry.
        if (stack.includes(current))
          await new Promise((done) => current.addEventListener('close', done, { once: true }));
        continue;
      }
      const closing = movement.get(current);
      if (!closing?.closing) break;
      await closing.finished;
    }
  } finally {
    requesting = false;
    controls?.querySelectorAll('button').forEach((button) => (button.disabled = false));
  }
}

function showControls() {
  controls?.remove();
  controls = undefined;
  if (stack.length < 2) return;
  const panel = document.createElement('div');
  panel.className = 'dialog-stack-controls';
  panel.setAttribute('popover', 'manual');
  for (const parent of stack.slice(0, -1)) {
    const title = dialogTitle(parent);
    const button = document.createElement('button');
    button.type = 'button';
    button.disabled = requesting;
    button.setAttribute(
      'aria-label',
      t(':name und darüberliegende Dialoge schließen', { name: title }),
    );
    button.title = button.getAttribute('aria-label');
    const name = document.createElement('span');
    name.textContent = title;
    const close = document.createElement('span');
    close.textContent = '×';
    close.setAttribute('aria-hidden', 'true');
    button.append(name, close);
    button.onclick = () => requestStackClose(parent);
    panel.append(button);
  }
  // A popover inside the active dialog escapes clipping while staying within
  // the native modal's interactive subtree. Background forms remain inert.
  stack.at(-1).append(panel);
  controls = panel;
  panel.showPopover();
  positionControls();
}

globalThis.addEventListener?.('resize', positionControls);

function timing(dialog, opening) {
  const style = getComputedStyle(dialog);
  const phase = opening ? 'enter' : 'exit';
  return {
    duration:
      document.hidden || matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 0
        : parseFloat(style.getPropertyValue(`--dialog-${phase}-duration`)),
    easing: style.getPropertyValue(`--dialog-${phase}-easing`).trim(),
    fill: 'both',
  };
}

function destination(dialog) {
  const style = getComputedStyle(dialog);
  return {
    transform: style.getPropertyValue('--dialog-closed-transform').trim(),
    opacity: style.getPropertyValue('--dialog-closed-opacity').trim(),
  };
}

function waitForMotion(animation, duration) {
  let timeout;
  return Promise.race([
    animation.finished.catch(() => {}),
    // Hidden tabs can freeze a timeline after the movement has begun.
    new Promise((done) => (timeout = setTimeout(done, duration + 120))),
  ]).then(() => clearTimeout(timeout));
}

function arrangeStack() {
  const currentRevision = ++revision;
  const motions = [];
  controls?.remove();
  controls = undefined;
  stack.forEach((dialog, index) => {
    const depth = stack.length - index - 1;
    const previous = suspended.get(dialog);
    const style = getComputedStyle(dialog);
    const start = { transform: style.transform, translate: style.translate, height: style.height };
    previous?.cancel();
    const entering = movement.get(dialog);
    if (depth && entering && !entering.closing) {
      entering.animation.cancel();
      movement.delete(dialog);
      delete dialog.dataset.dialogOpening;
    }
    dialog.style.setProperty('--dialog-stack-depth', Math.min(depth, 3));
    dialog.style.setProperty('--dialog-stack-count', Math.min(stack.length - 1, 3));
    dialog.toggleAttribute('data-dialog-covered', depth > 0);
    dialog.toggleAttribute('data-dialog-child', index > 0);
    const entry = entries.get(dialog);
    dialog.inert = depth > 0 || entry.inert;
    // aria-hidden cannot be undone by a descendant native dialog. Native modal
    // inertness still hides the covered parent while its child remains accessible.
    if (depth && !dialog.contains?.(stack.at(-1))) dialog.setAttribute('aria-hidden', 'true');
    else if (entry.ariaHidden === null) dialog.removeAttribute('aria-hidden');
    else dialog.setAttribute('aria-hidden', entry.ariaHidden);
    const finalStyle = getComputedStyle(dialog);
    const end = {
      transform: finalStyle.transform,
      translate: finalStyle.translate,
      height: finalStyle.height,
    };
    const options = timing(dialog, true);
    if (
      Object.keys(start).every((key) => start[key] === end[key]) ||
      !options.duration ||
      entering?.closing
    )
      return;
    const animation = dialog.animate([start, end], options);
    suspended.set(dialog, animation);
    motions.push(
      waitForMotion(animation, options.duration).then(() => {
        if (suspended.get(dialog) !== animation) return;
        animation.cancel();
        suspended.delete(dialog);
      }),
    );
  });
  Promise.all(motions).then(async () => {
    await movement.get(stack.at(-1))?.finished;
    if (currentRevision === revision) showControls();
  });
}

function forget(dialog) {
  const index = stack.indexOf(dialog);
  if (index < 0) return;
  const wasTop = index === stack.length - 1;
  const entry = entries.get(dialog);
  stack.splice(index, 1);
  suspended.get(dialog)?.cancel();
  suspended.delete(dialog);
  dialog.inert = entry.inert;
  if (entry.ariaHidden === null) dialog.removeAttribute('aria-hidden');
  else dialog.setAttribute('aria-hidden', entry.ariaHidden);
  dialog.removeAttribute('data-dialog-covered');
  dialog.removeAttribute('data-dialog-child');
  dialog.style.removeProperty('--dialog-stack-depth');
  dialog.style.removeProperty('--dialog-stack-count');
  entries.delete(dialog);
  arrangeStack();
  if (wasTop) return entry.focus;
}

export function openDialog(dialog, { from, animate = true } = {}) {
  if (!dialog || dialog.open) return Promise.resolve();
  const entry = {
    focus: document.activeElement,
    inert: dialog.inert,
    ariaHidden: dialog.getAttribute('aria-hidden'),
  };
  entries.set(dialog, entry);
  dialog.dataset.dialogOpening = '';
  dialog.showModal();
  stack.push(dialog);
  dialog.addEventListener(
    'close',
    () => {
      // Also recover if native code closes a dialog without going through our animation.
      if (entries.get(dialog) !== entry) return;
      const focus = forget(dialog);
      movement.get(dialog)?.animation.cancel();
      movement.delete(dialog);
      if (focus?.isConnected && !focus.closest('[inert]')) focus.focus({ preventScroll: true });
    },
    { once: true },
  );
  const cancel = (event) => {
    if (event.defaultPrevented) return;
    event.preventDefault();
    closeDialog(dialog);
  };
  dialog.addEventListener('cancel', cancel);
  dialog.addEventListener('close', () => dialog.removeEventListener('cancel', cancel), {
    once: true,
  });
  arrangeStack();
  const options = timing(dialog, true);
  if (!animate || !options.duration) {
    delete dialog.dataset.dialogOpening;
    return Promise.resolve();
  }
  const start = typeof from === 'function' ? from() : from || destination(dialog);
  const animation = dialog.animate(
    [start, { transform: 'none', opacity: 1, borderRadius: getComputedStyle(dialog).borderRadius }],
    options,
  );
  const state = { animation, closing: false };
  movement.set(dialog, state);
  getComputedStyle(dialog, '::backdrop').opacity;
  requestAnimationFrame(() => {
    if (movement.get(dialog) === state && !state.closing) delete dialog.dataset.dialogOpening;
  });
  state.finished = waitForMotion(animation, options.duration).then(() => {
    if (movement.get(dialog) !== state) return;
    animation.cancel();
    movement.delete(dialog);
    delete dialog.dataset.dialogOpening;
  });
  return state.finished;
}

export function closeDialog(dialog, { to, returnValue = '' } = {}) {
  if (!dialog?.open) return Promise.resolve();
  const index = stack.indexOf(dialog);
  if (index >= 0 && index < stack.length - 1) {
    return closeDialog(stack.at(-1)).then(() => closeDialog(dialog, { to, returnValue }));
  }
  const previous = movement.get(dialog);
  if (previous?.closing) return previous.finished;
  const style = getComputedStyle(dialog);
  const start = {
    transform: style.transform,
    opacity: style.opacity,
    borderRadius: style.borderRadius,
  };
  previous?.animation.cancel();
  delete dialog.dataset.dialogOpening;
  dialog.dataset.dialogClosing = '';
  const options = timing(dialog, false);
  const finish = () => {
    const focus = forget(dialog);
    dialog.close(returnValue);
    movement.get(dialog)?.animation.cancel();
    movement.delete(dialog);
    delete dialog.dataset.dialogClosing;
    if (focus?.isConnected && !focus.closest('[inert]')) focus.focus({ preventScroll: true });
  };
  if (!options.duration) {
    finish();
    return Promise.resolve();
  }
  const animation = dialog.animate([start, to || destination(dialog)], options);
  const state = { animation, closing: true };
  movement.set(dialog, state);
  state.finished = waitForMotion(animation, options.duration).then(finish);
  return state.finished;
}
