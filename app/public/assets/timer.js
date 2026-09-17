// The server owns the elapsed time. This only renders it and asks for a change, so a
// reload, a sleeping laptop or a second tab can never invent or lose a minute.

// Nothing at all happens for this long, so an ordinary click never reveals the outline.
const GRACE = 160;
// How long the outline then takes to close, and with it how long a hold has to last.
const SWEEP = 620;
// How long the button states what it just did before going back to showing the clock.
const CONFIRM = 1200;

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

// The same shape the server writes and the field accepts, so the number the button just
// booked and the number the row shows cannot drift apart.
function duration(minutes) {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  if (hours && rest) return `${hours}h ${rest}m`;

  return hours ? `${hours}h` : `${rest}m`;
}

// Settling a run moves whole minutes onto the ticket. The row that holds them says so: it
// takes the new total and lights up once. Nothing lights up when nothing was booked, because
// a run shorter than a minute leaves the total where it was.
function booked(panel, minutes) {
  if (typeof minutes !== 'number') return;
  const field = panel
    .closest('.ticket-spent')
    ?.querySelector('[data-inline-field="spent_minutes"]');
  const display = field?.querySelector('.inline-display');
  const input = field?.querySelector('input[name="spent_minutes"]');
  const text = duration(minutes);
  if (input) input.value = text;
  if (!display || display.textContent.trim() === text) return;
  display.textContent = text;
  display.classList.remove('just-booked');
  // Reading the layout restarts the animation when two runs settle close together.
  void display.offsetWidth;
  display.classList.add('just-booked');
}

function hasTime(panel) {
  return Number(panel.dataset.seconds || 0) > 0 && panel.dataset.state !== 'stopped';
}

// The button shows the clock, and the icon whenever it has to say what a press would do:
// when the pointer arrives, and for a moment after the state changed. The way back to the
// clock deliberately ignores the pointer — after a click it is still on the button, and
// waiting for it to leave would strand the icon there for as long as the hand rests.
function face(panel, showing) {
  panel.dataset.face = showing;
  clearTimeout(panel.confirming);
  if (showing !== 'icon' || !hasTime(panel)) return;
  panel.confirming = setTimeout(() => {
    if (!panel.holding) panel.dataset.face = 'time';
  }, CONFIRM);
}

function render(panel, confirm = false) {
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
  panel.stopFollowing = null;
  if (state === 'running') {
    panel.stopFollowing = follow(panel, seconds);
  } else {
    const readout = panel.querySelector('[data-timer-readout]');
    if (readout) readout.textContent = clock(seconds);
  }
  // A timer that has never run has no time worth showing, so it stays an icon.
  face(panel, confirm || !hasTime(panel) ? 'icon' : 'time');
}

// The card behind the panel and the marker in the bar are rendered by the server, so they
// would otherwise only catch up on the next load. Both are kept in step here instead.
function marker(panel, active) {
  const where = /\/projects\/(\d+)\/tickets\/([^/]+)\/timer$/.exec(panel.dataset.action || '');
  if (!where) return;
  const [, project, reference] = where;
  const card = document.querySelector(`.ticket-card[data-key="${CSS.escape(reference)}"]`);
  const dot = card?.querySelector('.card-top > .timer-pulse');
  if (card && active && !dot) {
    card
      .querySelector('.card-top')
      ?.insertBefore(
        Object.assign(document.createElement('span'), { className: 'timer-pulse' }),
        card.querySelector('.card-top')?.firstElementChild?.nextSibling ?? null,
      );
  } else if (dot && !active) {
    dot.remove();
  }

  const bar = document.querySelector('.topbar-right');
  let chip = document.querySelector('[data-running-timer]');
  if (!active) {
    chip?.remove();

    return;
  }
  if (!chip && bar) {
    chip = document.createElement('a');
    chip.className = 'timer-chip';
    chip.dataset.runningTimer = '';
    chip.innerHTML =
      '<span class="timer-pulse" aria-hidden="true"></span><span class="timer-ticket"></span><time data-timer-readout></time>';
    bar.prepend(chip);
  }
  if (!chip) return;
  chip.href = `/projects/${project}/tickets/${encodeURIComponent(reference)}`;
  chip.querySelector('.timer-ticket').textContent = reference;
  chip.stopFollowing?.();
  chip.stopFollowing = follow(chip, Number(panel.dataset.seconds || 0));
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
    render(panel, true);
    marker(panel, panel.dataset.state === 'running');
    booked(panel, payload.spent_minutes);
  } finally {
    delete panel.dataset.busy;
  }
}

// The outline is drawn around the button as it actually is, which changes width with the
// time inside it, so its geometry is taken from the element rather than written down.
function outline(panel, button) {
  const ring = panel.querySelector('.timer-hold');
  const track = panel.querySelector('.timer-track');
  if (!ring) return { ring: null, length: 0 };
  const box = button.getBoundingClientRect();
  const stroke = 2.6;
  const inset = stroke / 2 + 0.05;
  const width = Math.max(0, box.width - stroke - 0.1);
  const height = Math.max(0, box.height - stroke - 0.1);
  const radius = Math.min(height / 2, 9.7);
  for (const rect of [track, ring]) {
    if (!rect) continue;
    rect.setAttribute('x', String(inset));
    rect.setAttribute('y', String(inset));
    rect.setAttribute('width', String(width));
    rect.setAttribute('height', String(height));
    rect.setAttribute('rx', String(radius));
  }
  panel.querySelector('.timer-ring')?.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);

  return {
    ring,
    length: 2 * (width - 2 * radius) + 2 * (height - 2 * radius) + 2 * Math.PI * radius,
  };
}

// Pressing a running timer stops the clock at once rather than at the end of the hold, so
// holding the button cannot book the time the hold itself takes. Letting go early leaves it
// paused, which is what a tap means anyway; holding on turns that pause into a full stop.
function hold(panel, button) {
  if (panel.holding || panel.dataset.busy) return;
  const state = { reached: false, animation: null, settled: false };
  panel.holding = state;
  face(panel, 'icon');
  if (panel.dataset.state === 'running') {
    state.settled = true;
    send(panel, 'pause');
  }
  state.sweep = setTimeout(() => {
    const { ring, length } = outline(panel, button);
    if (!ring) return;
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
    setTimeout(() => {
      current.animation?.cancel();
      delete panel.dataset.holding;
      delete panel.dataset.held;
    }, 240);

    return;
  }
  current.animation?.cancel();
  delete panel.dataset.holding;
  // A running timer was already paused on the press, and letting go is not a new command.
  if (act && !current.settled) send(panel, 'start');
}

for (const panel of document.querySelectorAll('[data-ticket-timer]')) render(panel);

document.addEventListener('pointerdown', (event) => {
  const button = event.target.closest?.('[data-timer-toggle]');
  const panel = button?.closest('[data-ticket-timer]');
  if (!panel || event.button !== 0) return;
  event.preventDefault();
  try {
    // Keeps the release reachable when the pointer drifts off the button while held.
    button.setPointerCapture(event.pointerId);
  } catch {
    // An unknown pointer id is not worth failing the gesture over.
  }
  hold(panel, button);
});
document.addEventListener('pointerup', (event) => {
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (panel) release(panel);
});
document.addEventListener('pointercancel', (event) => {
  const panel = event.target.closest?.('[data-timer-toggle]')?.closest('[data-ticket-timer]');
  if (panel) release(panel, false);
});

// Under the pointer the button says what it does; away from it, it goes back to the clock.
document.addEventListener('pointerover', (event) => {
  const panel = event.target.closest?.('[data-ticket-timer]');
  if (panel) face(panel, 'icon');
});
document.addEventListener('pointerout', (event) => {
  const panel = event.target.closest?.('[data-ticket-timer]');
  if (panel && !panel.contains(event.relatedTarget) && !panel.holding) {
    clearTimeout(panel.confirming);
    panel.dataset.face = hasTime(panel) ? 'time' : 'icon';
  }
});

// Space and Enter hold too, so the stop is reachable without a pointer. Key repeat would
// otherwise restart the hold on every repeated keydown.
document.addEventListener('keydown', (event) => {
  if (event.key !== ' ' && event.key !== 'Enter') return;
  const button = event.target.closest?.('[data-timer-toggle]');
  const panel = button?.closest('[data-ticket-timer]');
  if (!panel || event.repeat) return;
  event.preventDefault();
  hold(panel, button);
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
