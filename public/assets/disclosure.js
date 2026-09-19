// A native details element opens and shuts in one frame, which drops everything below it a
// whole section's height without warning. This gives every disclosure in the application the
// same measured movement, so the eye can follow where the page went.
//
// The element's own height is animated rather than a wrapper's, because several views replace
// the contents of a details element and would throw any wrapper away with it.

const OPENING = 260;
const CLOSING = 200;
// Decelerating rather than springy: a spring is all but finished at half its duration, which
// reads as a snap, and its overshoot fights a clipped height.
const EASE = 'cubic-bezier(.22,.7,.26,1)';
const still = matchMedia('(prefers-reduced-motion: reduce)');

function sweep(shell, summary) {
  const opening = !shell.open;
  const shut = summary.offsetHeight;
  shell.running?.cancel();
  if (opening) shell.open = true;
  const grown = opening ? shell.scrollHeight : shell.offsetHeight;
  shell.dataset.sweeping = '1';
  const duration = opening ? OPENING : CLOSING;
  const frames = opening
    ? [{ height: shut + 'px' }, { height: grown + 'px' }]
    : [{ height: grown + 'px' }, { height: shut + 'px' }];
  const run = shell.animate(frames, { duration, easing: EASE });
  shell.running = run;

  // A background tab freezes the timeline; a section must not stay open because its closing
  // animation never got a frame.
  Promise.race([
    run.finished.catch(() => {}),
    new Promise((done) => setTimeout(done, duration + 120)),
  ]).then(() => {
    if (shell.running !== run) return;
    shell.running = null;
    delete shell.dataset.sweeping;
    if (!opening) shell.open = false;
    else settle(shell);
  });
}

// Just enough guidance to keep the place: if opening pushed the section past the bottom of
// the window, bring it back into view. Nothing moves when it was visible all along.
function settle(shell) {
  const box = shell.getBoundingClientRect();
  const room = window.innerHeight || document.documentElement.clientHeight;
  if (box.bottom <= room - 8 || box.height > room) return;
  shell.scrollIntoView({
    block: 'nearest',
    behavior: still.matches ? 'auto' : 'smooth',
  });
}

document.addEventListener('click', (event) => {
  const summary = event.target.closest?.('summary');
  const shell = summary?.parentElement;
  if (!summary || shell?.tagName !== 'DETAILS' || still.matches) return;
  // A menu lies over the page instead of growing inside it, so it keeps the native toggle.
  if (shell.hasAttribute('data-menu')) return;
  // Anything inside the summary that is a control of its own keeps its click.
  if (event.target.closest('a,button,input,select,textarea') !== null) return;
  event.preventDefault();
  sweep(shell, summary);
});

// A menu closes the way menus do: by clicking elsewhere or pressing Escape.
document.addEventListener('pointerdown', (event) => {
  for (const menu of document.querySelectorAll('[data-menu][open]')) {
    if (!menu.contains(event.target)) menu.open = false;
  }
});
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  for (const menu of document.querySelectorAll('[data-menu][open]')) {
    menu.open = false;
    menu.querySelector('summary')?.focus();
  }
});
