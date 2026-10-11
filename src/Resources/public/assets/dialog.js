// Every dialog stays in the native top layer until its return movement ends.
// CSS supplies the common timing; a drawer only changes the destination.
const movement = new WeakMap();

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

export function openDialog(dialog, { from, animate = true } = {}) {
  if (!dialog || dialog.open) return Promise.resolve();
  dialog.dataset.dialogOpening = '';
  dialog.showModal();
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
  // Commit the transparent backdrop before allowing it to fade in.
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
    // close() and its cleanup listeners run only after the last visible frame.
    dialog.close(returnValue);
    movement.get(dialog)?.animation.cancel();
    movement.delete(dialog);
    delete dialog.dataset.dialogClosing;
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
