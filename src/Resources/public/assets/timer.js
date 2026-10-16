import { t } from './i18n.js';
import { toast } from './app.js';
import { openDialog, closeDialog } from './dialog.js';
import { refresh } from './ticket.js?v=3';

// The server owns the elapsed time. This only renders it and asks for a change, so a
// reload, a sleeping laptop or a second tab can never invent or lose a minute.
//
// In the ticket the timer is a small stopwatch in the recorded-time row. It opens a dialog
// that says which ticket it counts on, starts and pauses with one button and books with
// another -- booking is its own, plainly labelled step, never a side effect of a gesture.

function clock(seconds) {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const rest = seconds % 60;
  const pad = (value) => String(value).padStart(2, '0');

  return hours ? `${hours}:${pad(minutes)}:${pad(rest)}` : `${minutes}:${pad(rest)}`;
}

// Counting from a fixed origin rather than adding one per tick, because a throttled
// background tab fires far fewer ticks than it has seconds. `paint` gets every new value,
// so the dialog can keep what booking would add in step with the clock.
function follow(element, seconds, paint = () => {}) {
  const readouts = element.querySelectorAll('[data-timer-readout]');
  const origin = Date.now() - seconds * 1000;
  let handle;
  const tick = () => {
    // A ticket the drawer has replaced, or a marker that was removed, stops counting.
    if (!element.isConnected) {
      clearInterval(handle);

      return;
    }
    const now = Math.max(0, Math.round((Date.now() - origin) / 1000));
    readouts.forEach((readout) => (readout.textContent = clock(now)));
    paint(now);
  };
  tick();
  handle = setInterval(tick, 1000);

  return () => clearInterval(handle);
}

const running = document.querySelector('[data-running-timer]');
if (running) follow(running, Number(running.dataset.seconds || 0));

// Whole minutes go onto the ticket; the seconds left over from earlier bookings count
// towards the next one. This is the same sum the server makes, so the button can say
// exactly what pressing it will add.
function bookable(panel, seconds) {
  return Math.floor((Number(panel.dataset.carry || 0) + seconds) / 60);
}

function describe(panel, seconds) {
  const dialog = panel.querySelector('[data-timer-dialog]') || panel.dialog;
  if (!dialog) return;
  const state = panel.dataset.state;
  const minutes = bookable(panel, seconds);
  const book = dialog.querySelector('[data-timer-action="stop"]');
  const label = dialog.querySelector('[data-timer-book-label]');
  const note = dialog.querySelector('[data-timer-note]');
  book.disabled = Boolean(panel.dataset.busy) || minutes < 1;
  label.textContent = minutes > 0 ? t(':minutes Min. buchen', { minutes }) : t('Buchen');
  if (seconds > 0 && minutes < 1) {
    note.textContent = t(
      'Gebucht wird ab einer vollen Minute. Was darunter liegt, zählt beim nächsten Buchen mit.',
    );
  } else if (state === 'stopped' && !seconds) {
    note.textContent = t(
      'Die Uhr läuft auf dem Server, auch wenn du das Ticket schließt. Erst Buchen trägt die Zeit unter Erfasst ein.',
    );
  } else {
    note.textContent = t(
      'Buchen trägt die vollen Minuten unter Erfasst ein und setzt die Uhr zurück.',
    );
  }
  // Seconds left over from earlier bookings are invisible on a clock that starts at zero,
  // yet they decide when the next minute is full. Saying so keeps the button explicable.
  const carry = Number(panel.dataset.carry || 0);
  if (carry > 0 && (seconds > 0 || state !== 'stopped'))
    note.textContent += ` ${t('Dazu kommen :seconds s aus früheren Buchungen.', { seconds: carry })}`;
}

function render(panel) {
  const state = panel.dataset.state;
  const seconds = Number(panel.dataset.seconds || 0);
  const dialog = panel.querySelector('[data-timer-dialog]') || panel.dialog;
  // A timer that has never run has no time worth showing, so the row keeps just its symbol.
  panel.toggleAttribute('data-idle', state !== 'running' && seconds === 0);
  const trigger = panel.querySelector('[data-timer-open]');
  trigger?.setAttribute(
    'aria-label',
    state === 'running'
      ? t('Zeiterfassung läuft: :time', { time: clock(seconds) })
      : t('Zeiterfassung öffnen'),
  );
  if (dialog) {
    dialog.dataset.state = state;
    const run = dialog.querySelector('.timer-run');
    run.dataset.timerAction = state === 'running' ? 'pause' : 'start';
    run.querySelector('[data-timer-run-label]').textContent =
      state === 'running' ? t('Pausieren') : seconds > 0 ? t('Fortsetzen') : t('Starten');
    run.disabled = Boolean(panel.dataset.busy);
    // While it runs, the button the hand is most likely to want is booking.
    run.classList.toggle('primary', state !== 'running' && seconds === 0);
    run.classList.toggle('subtle', !(state !== 'running' && seconds === 0));
    dialog.querySelector('[data-timer-status]').textContent =
      state === 'running' ? t('Läuft') : seconds > 0 ? t('Pausiert') : t('Bereit');
    const elsewhere = dialog.querySelector('[data-timer-elsewhere]');
    const other = panel.elsewhere;
    if (other !== undefined) {
      elsewhere.hidden = !other;
      elsewhere.querySelector('[data-timer-elsewhere-text]').textContent = other
        ? t(
            'Deine Zeiterfassung läuft gerade auf :ticket. Starten pausiert sie dort, die Zeit bleibt erhalten.',
            { ticket: other.reference },
          )
        : '';
    }
  }
  panel.stopFollowing?.();
  panel.stopFollowing = null;
  const paint = (now) => describe(panel, now);
  if (state === 'running') {
    panel.stopFollowing = follow(panel, seconds, paint);
    if (dialog && !panel.contains(dialog)) {
      const stop = follow(dialog, seconds, paint);
      const previous = panel.stopFollowing;
      panel.stopFollowing = () => {
        previous();
        stop();
      };
    }
  } else {
    for (const root of new Set([panel, dialog].filter(Boolean)))
      root.querySelectorAll('[data-timer-readout]').forEach((readout) => {
        readout.textContent = clock(seconds);
      });
    paint(seconds);
  }
}

// The card behind the panel and the marker in the bar are rendered by the server, so they
// would otherwise only catch up on the next load. Both are kept in step here instead.
function marker(panel, active) {
  const where = /\/projects\/(\d+)\/tickets\/([^/]+)\/timer$/.exec(panel.dataset.action || '');
  if (!where) return;
  const [, project, reference] = where;
  const card = document.querySelector(`.ticket-card[data-key="${CSS.escape(reference)}"]`);
  const dot = card?.querySelector('[data-card-timer]');
  if (card && active && !dot) {
    const marker = document.createElement('span');
    marker.className = 'timer-pulse';
    marker.dataset.cardTimer = '';
    marker.title = t('Deine Zeiterfassung läuft');
    const label = document.createElement('span');
    label.className = 'sr-only';
    label.textContent = marker.title;
    marker.append(label);
    card.querySelector('.card-id')?.append(marker);
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
  const dialog = panel.dialog || panel.querySelector('[data-timer-dialog]');
  const errors = dialog?.querySelector('.form-errors');
  if (errors) {
    errors.textContent = '';
    errors.classList.remove('visible');
  }
  panel.dataset.busy = '1';
  render(panel);
  const before = panel.dataset.state;
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
      if (errors) {
        errors.textContent = payload.message || t('Die Zeiterfassung ließ sich nicht ändern.');
        errors.classList.add('visible');
      }

      return null;
    }
    panel.dataset.state = payload.state ?? 'stopped';
    panel.dataset.seconds = String(payload.seconds ?? 0);
    panel.dataset.carry = String(payload.carry ?? 0);
    panel.elsewhere = payload.elsewhere ?? null;
    // Starting here paused whatever ran elsewhere, so its card stops pulsing too.
    if (action === 'start')
      document.querySelectorAll('[data-card-timer]').forEach((dot) => dot.remove());
    if (action !== 'state' || before !== panel.dataset.state)
      marker(panel, panel.dataset.state === 'running');

    return payload;
  } catch {
    if (errors) {
      errors.textContent = t('Die Zeiterfassung ließ sich nicht ändern.');
      errors.classList.add('visible');
    }

    return null;
  } finally {
    delete panel.dataset.busy;
    render(panel);
  }
}

// Booking changed the ticket -- its total, its version, its history -- so the ticket is
// reloaded the way every other save reloads it. Editing a field afterwards then carries the
// current version instead of running into a conflict.
async function book(panel) {
  const payload = await send(panel, 'stop');
  if (!payload) return;
  const workspace = panel.closest('.ticket-workspace');
  await dismiss(panel);
  if (payload.booked > 0)
    toast(
      t(':minutes Min. auf :ticket gebucht', {
        minutes: payload.booked,
        ticket: panel.dataset.reference,
      }),
    );
  if (workspace) await refresh(workspace, null, null).catch(() => {});
  const field = workspace?.querySelector('[data-inline-field="spent_minutes"] .inline-display');
  if (field) {
    field.classList.remove('just-booked');
    void field.offsetWidth;
    field.classList.add('just-booked');
  }
}

// The dialog is moved to the end of the document while it is open, so it is never inside
// the drawer it was opened from: a covered drawer is inert, and so would be the timer. It
// goes back into its row when it closes, which keeps it with the ticket it belongs to.
async function open(panel) {
  const dialog = panel.querySelector('[data-timer-dialog]');
  if (!dialog || dialog.open) return;
  panel.dialog = dialog;
  dialog.timerPanel = panel;
  document.body.append(dialog);
  dialog.addEventListener('close', () => restore(panel), { once: true });
  render(panel);
  await openDialog(dialog);
  // Whatever another tab or another ticket did in the meantime, the dialog shows the truth.
  await send(panel, 'state');
  dialog.querySelector('[data-timer-action]:not(:disabled)')?.focus();
}

// Back into its row, or gone with a ticket that is no longer on the page. Called by the
// close event and again once a close this module asked for has finished, because a page
// in the background may deliver that event late.
function restore(panel) {
  const dialog = panel.dialog;
  if (!dialog || dialog.open) return;
  if (panel.isConnected) {
    if (!panel.contains(dialog)) panel.append(dialog);
  } else dialog.remove();
  render(panel);
}

async function dismiss(panel) {
  await closeDialog(panel.dialog);
  restore(panel);
}

function panelOf(target) {
  return (
    target.closest?.('[data-ticket-timer]') || target.closest?.('[data-timer-dialog]')?.timerPanel
  );
}

document.addEventListener('click', (event) => {
  const opener = event.target.closest?.('[data-timer-open]');
  if (opener) {
    open(opener.closest('[data-ticket-timer]'));

    return;
  }
  const panel = panelOf(event.target);
  if (!panel || panel.dataset.busy) return;
  if (event.target.closest('[data-timer-close]')) {
    dismiss(panel);

    return;
  }
  const button = event.target.closest('[data-timer-action]');
  const action = button?.dataset.timerAction;
  if (action === 'stop') book(panel);
  else if (action)
    // The button is disabled while the request runs, which drops its focus. It gets it
    // back, so the keyboard stays in the dialog.
    send(panel, action).then(() => {
      if (panel.dialog?.open && !panel.dialog.contains(document.activeElement)) button.focus();
    });
});

for (const panel of document.querySelectorAll('[data-ticket-timer]')) render(panel);

// The drawer renders its ticket after this module has run, so a freshly opened one is
// wired when it announces itself.
document.addEventListener('nafinity:ticket-opened', () => {
  for (const panel of document.querySelectorAll('[data-ticket-timer]')) render(panel);
});
