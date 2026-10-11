// One native top-layer stack for every caller, including declarative data-dialog buttons.
// A parent keeps its DOM, scroll position and focus until the last child returns.
const movement = new WeakMap();
const suspended = new WeakMap();
const entries = new WeakMap();
const stack = [];

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
  stack.forEach((dialog, index) => {
    const depth = stack.length - index - 1;
    const previous = suspended.get(dialog);
    const start = { transform: getComputedStyle(dialog).transform };
    previous?.cancel();
    const entering = movement.get(dialog);
    if (depth && entering && !entering.closing) {
      entering.animation.cancel();
      movement.delete(dialog);
      delete dialog.dataset.dialogOpening;
    }
    dialog.style.setProperty('--dialog-stack-depth', Math.min(depth, 3));
    dialog.toggleAttribute('data-dialog-covered', depth > 0);
    dialog.toggleAttribute('data-dialog-child', index > 0);
    const entry = entries.get(dialog);
    dialog.inert = depth > 0 || entry.inert;
    if (depth) dialog.setAttribute('aria-hidden', 'true');
    else if (entry.ariaHidden === null) dialog.removeAttribute('aria-hidden');
    else dialog.setAttribute('aria-hidden', entry.ariaHidden);
    const end = { transform: getComputedStyle(dialog).transform };
    const options = timing(dialog, true);
    if (start.transform === end.transform || !options.duration || entering?.closing) return;
    const animation = dialog.animate([start, end], options);
    suspended.set(dialog, animation);
    waitForMotion(animation, options.duration).then(() => {
      if (suspended.get(dialog) !== animation) return;
      animation.cancel();
      suspended.delete(dialog);
    });
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
  return waitForMotion(animation, options.duration).then(() => {
    if (movement.get(dialog) !== state) return;
    animation.cancel();
    movement.delete(dialog);
    delete dialog.dataset.dialogOpening;
  });
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
