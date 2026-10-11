import { mountEmailDesigner } from './email-design.js';
import { openDialog, closeDialog } from './dialog.js';
import { t } from './i18n.js';
import { mountThemeManager } from './themes.js';
import { mountBrandingPreview } from './branding-preview.js';
import { mountAdvancedSettings } from './settings-configuration.js';
import { mountMemberControls } from './members.js';
import { mountPeopleDirectory } from './people.js?v=3';
document.querySelectorAll('[data-people-directory]').forEach(mountPeopleDirectory);

mountMemberControls();
document.querySelectorAll('[data-email-design]').forEach(mountEmailDesigner);
document.querySelectorAll('[data-theme-manager]').forEach(mountThemeManager);
document.querySelectorAll('[data-branding-form]').forEach((form) => mountBrandingPreview(form));

let settingsStorage;
try {
  settingsStorage = window.sessionStorage;
} catch {}
const advancedSettings = mountAdvancedSettings(document, settingsStorage);

const dialog = document.querySelector('#settings-detail');
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
let activeCard;
let activeContent;
let placeholder;
let closing = false;
let cardOrigin;

function cardTransform() {
  const from = activeCard.getBoundingClientRect();
  const to = dialog.getBoundingClientRect();
  return `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${from.height / to.height})`;
}

// The content follows the shell, avoiding scaled text during the card morph.
const REVEAL = 160;
function cardFrame() {
  return {
    transform: cardTransform(),
    opacity: 1,
    borderRadius:
      getComputedStyle(document.documentElement).getPropertyValue('--radius-24').trim() || '0px',
  };
}

async function openCard(id, animate = true) {
  if (!dialog || activeCard) return;
  const card = document.querySelector(`[data-settings-open="${CSS.escape(id)}"]`);
  const content = document.querySelector(`[data-settings-content="${CSS.escape(id)}"]`);
  if (!card || !content) return;
  if (card.closest('[data-settings-advanced-group]')) advancedSettings?.show();
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
  const travel = openDialog(dialog, { from: () => (cardOrigin = cardFrame()), animate });
  card.classList.add('is-open');
  card.setAttribute('aria-expanded', 'true');
  history.replaceState(history.state, '', `#${id}`);
  if (animate) {
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
  await closeDialog(dialog, { to: cardOrigin || cardFrame() });
  activeContent.hidden = true;
  placeholder.replaceWith(activeContent);
  activeCard.classList.remove('is-open');
  activeCard.setAttribute('aria-expanded', 'false');
  document.documentElement.classList.remove('settings-open');
  dialog.classList.remove('is-moving');
  activeCard.focus({ preventScroll: true });
  activeCard = null;
  activeContent = null;
  cardOrigin = null;
  closing = false;
  history.replaceState(history.state, '', location.pathname + location.search);
}

document.querySelectorAll('[data-settings-open]').forEach((card) => {
  card.addEventListener('click', () => openCard(card.dataset.settingsOpen));
});
dialog?.querySelector('[data-settings-close]').addEventListener('click', closeCard);
dialog?.addEventListener('cancel', (event) => {
  if (event.target !== dialog) return;
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
      summary = host ? t('Kein Modell gewählt') + ' · ' + host : t('Kein Modell gewählt');
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
    const input = palette.querySelector('.field-custom');
    input.value = swatch.dataset.color;
    input.dispatchEvent(new Event('input', { bubbles: true }));
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

// Editing a displayed config value explicitly selects an administration override.
document.addEventListener('input', (event) => {
  const field = event.target.closest?.('[data-configuration-field]');
  if (!field || event.target.matches('[data-configuration-source]')) return;
  const source = field.querySelector('[data-configuration-source]');
  if (source) source.value = 'administration';
});
