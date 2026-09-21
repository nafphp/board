const dialog = document.querySelector('#settings-detail');
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let activeCard;
let activeContent;
let placeholder;
let animation;
let closing = false;

function cardTransform() {
  const from = activeCard.getBoundingClientRect();
  const to = dialog.getBoundingClientRect();
  return `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${from.height / to.height})`;
}

const DURATION = 360;
// The contents come in behind the shell rather than after it has landed. The
// shell eases hard out and is visually there long before it stops, so waiting
// for it reads as two separate movements instead of one.
const REVEAL = 160;

/** Resolve when the animation is done, or when it plainly never will be.
 *
 * A hidden tab freezes the timeline, and a dialog must not sit there with its
 * contents held back because its opening animation never got a frame.
 */
function settled(run, duration) {
  return Promise.race([
    run.finished.catch(() => {}),
    new Promise((done) => setTimeout(done, duration + 120)),
  ]);
}

function animateCard(opening) {
  animation?.cancel();
  // Measured without a transform of its own, or an opening that is still
  // running would be measured instead of the dialog.
  dialog.style.transform = '';
  const small = cardTransform();
  const duration = reducedMotion.matches ? 0 : DURATION;

  // The frame before the animation has one. An animation is only picked up on
  // the next frame, so without this the dialog is painted once at full size and
  // only then jumps back to the card to grow -- the flash that reads as the
  // dialog opening, disappearing and coming back. Not without a duration: there
  // the animation is over before that frame arrives, and the value would be all
  // anyone saw.
  const leads = opening && duration > 0;
  if (leads) dialog.style.transform = small;

  animation = dialog.animate(
    opening
      ? [
          { transform: small, borderRadius: '24px' },
          { transform: 'none', borderRadius: '20px' },
        ]
      : [
          { transform: 'none', borderRadius: '20px' },
          { transform: small, borderRadius: '24px' },
        ],
    { duration, easing: 'cubic-bezier(.22,1,.36,1)' },
  );

  // Once it is running the animation owns the transform, and the inline value
  // has to go: this animation fills neither end, so when it finishes the
  // element falls back to whatever style it finds -- and finding the card-sized
  // transform there would shrink the open dialog back down.
  if (leads) {
    animation.ready
      .catch(() => {})
      .then(() => {
        dialog.style.transform = '';
      });
  }

  return settled(animation, duration);
}

async function openCard(id, animate = true) {
  if (!dialog || activeCard) return;
  const card = document.querySelector(`[data-settings-open="${CSS.escape(id)}"]`);
  const content = document.querySelector(`[data-settings-content="${CSS.escape(id)}"]`);
  if (!card || !content) return;
  activeCard = card;
  activeContent = content;
  placeholder = document.createComment('settings content');
  content.replaceWith(placeholder);
  dialog.querySelector('.settings-detail-body').append(content);
  content.hidden = false;
  document.querySelector('#settings-detail-title').textContent = content.dataset.title;
  document.querySelector('#settings-detail-description').textContent = content.dataset.description;
  dialog.querySelector('[data-settings-icon]').innerHTML =
    card.querySelector('.settings-icon').innerHTML;
  document.documentElement.classList.add('settings-open');
  // Before showModal, so the dialog's first painted frame is already without
  // contents. Set afterwards it would be one frame late, and the contents would
  // be shown and taken away again.
  if (animate) dialog.classList.add('is-moving');
  dialog.showModal();
  card.classList.add('is-open');
  card.setAttribute('aria-expanded', 'true');
  history.replaceState(history.state, '', `#${id}`);
  if (animate) {
    const travel = animateCard(true);
    const reveal = setTimeout(
      () => {
        if (!closing) dialog.classList.remove('is-moving');
      },
      reducedMotion.matches ? 0 : REVEAL,
    );
    await travel;
    clearTimeout(reveal);
    if (!closing) dialog.classList.remove('is-moving');
  }
  if (!closing) dialog.querySelector('[data-settings-close]').focus({ preventScroll: true });
}

async function closeCard() {
  if (!activeCard || closing) return;
  closing = true;
  dialog.classList.add('is-moving');
  await animateCard(false);
  dialog.close();
  activeContent.hidden = true;
  placeholder.replaceWith(activeContent);
  activeCard.classList.remove('is-open');
  activeCard.setAttribute('aria-expanded', 'false');
  document.documentElement.classList.remove('settings-open');
  dialog.classList.remove('is-moving');
  activeCard.focus({ preventScroll: true });
  activeCard = null;
  activeContent = null;
  closing = false;
  history.replaceState(history.state, '', location.pathname + location.search);
}

document.querySelectorAll('[data-settings-open]').forEach((card) => {
  card.addEventListener('click', () => openCard(card.dataset.settingsOpen));
});
dialog?.querySelector('[data-settings-close]').addEventListener('click', closeCard);
dialog?.addEventListener('cancel', (event) => {
  event.preventDefault();
  closeCard();
});

document
  .querySelector('[data-settings-project]')
  ?.addEventListener('change', (event) => location.assign(event.target.value));
if (dialog && location.hash) openCard(decodeURIComponent(location.hash.slice(1)), false);
window.addEventListener('hashchange', () => {
  if (!activeCard && location.hash) openCard(decodeURIComponent(location.hash.slice(1)));
});

// The assistant is configured per browser, so only the page itself knows its state.
const assistant = document.querySelector('[data-ai-settings]');
if (assistant) {
  import('./ai/store.js').then(({ configFor }) => {
    const config = configFor(assistant);
    let host = '';
    try {
      host = new URL(config.url).host;
    } catch {}
    let summary = 'Ausgeschaltet';
    if (config.enabled && config.model) summary = host ? `${config.model} · ${host}` : config.model;
    else if (config.enabled)
      summary = host ? `Kein Modell gewählt · ${host}` : 'Kein Modell gewählt';
    document.querySelector('[data-settings-summary="ai"]').textContent = summary;
    document
      .querySelector('[data-settings-content="ai"]')
      ?.setAttribute('data-description', summary);
  });
}

// The colour palette of a field, and the reorder buttons of a structure row.
//
// The colour input stays the field the form submits; a swatch only writes into
// it, so every colour field keeps working without this file. The reorder buttons
// sit inside the summary to be part of the row they move, and a click there
// would otherwise open the row as well -- preventing that takes the submit with
// it, so the form is asked to submit itself instead.
document.addEventListener('click', (event) => {
  const swatch = event.target.closest?.('.field-pick');
  if (swatch) {
    const palette = swatch.closest('.field-palette');
    palette.querySelector('.field-custom').value = swatch.dataset.color;
    for (const pick of palette.querySelectorAll('.field-pick')) {
      pick.setAttribute('aria-pressed', String(pick === swatch));
    }
    return;
  }

  const nudge = event.target.closest?.('.structure-nudge');
  if (!nudge || nudge.disabled) return;
  event.preventDefault();
  document.getElementById(nudge.getAttribute('form'))?.requestSubmit(nudge);
});

// A colour the picker produced belongs to none of the swatches.
document.addEventListener('input', (event) => {
  const custom = event.target.closest?.('.field-custom');
  if (!custom) return;
  for (const pick of custom.closest('.field-palette').querySelectorAll('.field-pick')) {
    pick.setAttribute('aria-pressed', String(pick.dataset.color === custom.value));
  }
});
