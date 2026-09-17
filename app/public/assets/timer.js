// The server owns the elapsed time. This only renders it and asks for a change, so a
// reload, a sleeping laptop or a second tab can never invent or lose a minute.

// Nothing at all happens for this long, so an ordinary click never reveals the outline.
const GRACE = 160;
// How long the outline then takes to close, and with it how long a hold has to last.
const SWEEP = 620;

// The outline is a rounded rectangle, so its length is the straight sides plus one full
// circle worth of corners. Measured off the element rather than hard-coded, so the shape
// and the animation can never drift apart.
function perimeter(rect) {
  const width = Number(rect.getAttribute('width'));
  const height = Number(rect.getAttribute('height'));
  const radius = Number(rect.getAttribute('rx'));

  return 2 * (width - 2 * radius) + 2 * (height - 2 * radius) + 2 * Math.PI * radius;
}

function clock(seconds) {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const rest = seconds % 60;
  const pad = (value) => String(value).padStart(2, '0');

  return hours ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${minutes}:${pad(rest)}`;
}

// Counting from a fixed origin rather than adding one per tick, because a throttled
// background tab fires far fewer ticks than it has seconds.
function follow(element, seconds) {
  const readout = element.querySelector('[data-timer-readout]');
  if (!readout) return () => {};
  const origin = Date.now() - seconds * 1000;
  const paint = () => {
    readout.textContent = clock(Math.max(0, Math.round((Date.now() - origin) / 1000)));
  };
  paint();
  const handle = setInterval(paint, 1000);

  return () => clearInterval(handle);
}

const running = document.querySelector('[data-running-timer]');
if (running) follow(running, Number(running.dataset.seconds || 0));

function render(panel) {
  const state = panel.dataset.state;
  const seconds = Number(panel.dataset.seconds || 0);
  const button = panel.querySelector('[data-timer-toggle]');
  if (button) {
    button.setAttribute(
      'aria-label',
      state === 'running'
        ? panel.dataset.labelPause
        : state === 'paused'
          ? panel.dataset.labelResume
          : panel.dataset.labelStart,
    );
    button.setAttribute('aria-pressed', state === 'running' ? 'true' : 'false');
  }
  panel.stopFollowing?.();
  if (state === 'running') {
    panel.stopFollowing = follow(panel, seconds);

    return;
  }
  panel.stopFollowing = null;
  const readout = panel.querySelector('[data-timer-readout]');
  if (readout) readout.textContent = clock(seconds);
}

async function send(panel, action) {
  const errors = panel.querySelector('.form-errors');
  if (errors) errors.textContent = '';
  panel.dataset.busy = '1';
  try {
    const response = await fetch(panel.dataset.action, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token':
          panel.dataset.token || document.querySelector('meta[name=csrf-token]')?.content || '',
      },
      body: JSON.stringify({ action }),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (errors)
        errors.textContent = payload.message || 'Die Zeiterfassung ließ sich nicht ändern.';

      return;
    }
    panel.dataset.state = payload.state ?? 'stopped';
    panel.dataset.seconds = String(payload.seconds ?? 0);
    render(panel);
    // Recorded minutes and the marker in the bar are rendered by the server, and settling a
    // run is the moment they change; the page catches up then rather than guessing.
    if (action === 'start') document.querySelector('[data-running-timer]')?.remove();
    else location.reload();
  } finally {
    delete panel.dataset.busy;
  }
}

// Holding the button closes an outline around it. The outline is the whole explanation of
// how long to hold, which is why stopping needs no label of its own — but it only appears
// once the press has outlasted a normal click, so tapping stays quiet.
function hold(panel) {
  if (panel.holding || panel.dataset.busy) return;
  const ring = panel.querySelector('.timer-hold');
  const state = { reached: false, animation: null };
  panel.holding = state;
  state.sweep = setTimeout(() => {
    if (!ring) return;
    const length = perimeter(ring);
    ring.style.strokeDasharray = String(length);
    panel.dataset.holding = '1';
    state.animation = ring.animate([{ strokeDashoffset: length }, { strokeDashoffset: 0 }], {
      duration: SWEEP,
      easing: 'linear',
      fill: 'forwards',
    });
  }, GRACE);
  state.stop = setTimeout(() => {
    state.reached = true;
    panel.dataset.held = '1';
    send(panel, 'stop');
  }, GRACE + SWEEP);
}

function release(panel, act = true) {
  const current = panel.holding;
  if (!current) return;
  panel.holding = null;
  clearTimeout(current.sweep);
  clearTimeout(current.stop);
  if (current.reached) {
    // The stop is already on its way; let the closed outline settle rather than snap back.
    setTimeout(() => {
      current.animation?.cancel();
      delete panel.dataset.holding;
      delete panel.dataset.held;
    }, 240);

    return;
  }
  current.animation?.cancel();
  delete panel.dataset.holding;
  if (act) send(panel, panel.dataset.state === 'running' ? 'pause' : 'start');
}

for (const panel of document.querySelectorAll('[data-ticket-timer]')) render(panel);

document.addEventListener('pointerdown', (event) => {
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (!panel || event.button !== 0) return;
  event.preventDefault();
  try {
    // Keeps the release reachable when the pointer drifts off the button while held.
    event.target.closest('[data-timer-toggle]').setPointerCapture(event.pointerId);
  } catch {
    // An unknown pointer id is not worth failing the gesture over.
  }
  hold(panel);
});
document.addEventListener('pointerup', (event) => {
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (panel) release(panel);
});
document.addEventListener('pointercancel', (event) => {
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (panel) release(panel, false);
});

// Space and Enter hold too, so the stop is reachable without a pointer. Key repeat would
// otherwise restart the hold on every repeated keydown.
document.addEventListener('keydown', (event) => {
  if (event.key !== ' ' && event.key !== 'Enter') return;
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (!panel || event.repeat) return;
  event.preventDefault();
  hold(panel);
});
document.addEventListener('keyup', (event) => {
  if (event.key !== ' ' && event.key !== 'Enter') return;
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (panel) release(panel);
});

// The drawer renders its ticket after this module has run, so a freshly opened one is
// wired when it announces itself.
document.addEventListener('nafinity:ticket-opened', () => {
  for (const panel of document.querySelectorAll('[data-ticket-timer]')) render(panel);
});
