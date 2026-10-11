import { mountEmailDesigner } from './email-design.js';
import { openDialog, closeDialog, mountDialogEditors } from './dialog.js';
import { t } from './i18n.js';
import { mountThemeManager } from './themes.js';
import { mountBrandingPreview } from './branding-preview.js';
import { mountAdvancedSettings } from './settings-configuration.js';
import { mountMemberControls } from './members.js';
import { mountPeopleDirectory } from './people.js?v=4';
mountDialogEditors(document);
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

const baseDialog = document.querySelector('#settings-detail');
const shell = baseDialog?.cloneNode(true);
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
const cards = [];
let serial = 0;

function rememberHash() {
  const id = cards.at(-1)?.id;
  history.replaceState(history.state, '', id ? `#${id}` : location.pathname + location.search);
}

function restoreCard(item) {
  if (!cards.includes(item)) return;
  item.content.hidden = true;
  item.placeholder.replaceWith(item.content);
  item.card.classList.remove('is-open');
  item.card.setAttribute('aria-expanded', 'false');
  item.card.removeAttribute('aria-controls');
  item.dialog.classList.remove('is-moving');
  cards.splice(cards.indexOf(item), 1);
  if (item.dialog !== baseDialog) item.dialog.remove();
  document.documentElement.classList.toggle('settings-open', cards.length > 0);
  rememberHash();
}

async function closeCard(item) {
  if (item.closing || !cards.includes(item)) return;
  item.closing = true;
  // Only the outer card morph hides content. Children return as complete panels.
  if (item.dialog === baseDialog) item.dialog.classList.add('is-moving');
  await closeDialog(item.dialog, { to: item.origin });
  restoreCard(item);
}

async function openCard(id, animate = true) {
  if (!baseDialog || cards.some((item) => item.id === id)) return;
  const card = document.querySelector(`[data-settings-open="${CSS.escape(id)}"]`);
  const content = document.querySelector(`[data-settings-content="${CSS.escape(id)}"]`);
  if (!card || !content) return;
  // A deep link restores the same parent chain as clicking the cards.
  if (content.dataset.parent) await openCard(content.dataset.parent, animate);
  if (card.closest('[data-settings-advanced-group]')) advancedSettings?.show();
  const dialog = cards.length ? shell.cloneNode(true) : baseDialog;
  const unique = ++serial;
  if (dialog !== baseDialog) {
    dialog.id = `settings-detail-${unique}`;
    document.body.append(dialog);
  }
  const title = dialog.querySelector('h2');
  const description = dialog.querySelector('.settings-detail-heading p');
  title.id = `settings-title-${unique}`;
  description.id = `settings-description-${unique}`;
  dialog.setAttribute('aria-labelledby', title.id);
  dialog.setAttribute('aria-describedby', description.id);
  title.textContent = content.dataset.title;
  description.textContent = content.dataset.description;
  dialog.querySelector('[data-settings-icon]').innerHTML =
    card.querySelector('.settings-icon').innerHTML;
  const item = {
    id,
    card,
    content,
    dialog,
    placeholder: document.createComment('settings content'),
    closing: false,
  };
  content.replaceWith(item.placeholder);
  dialog.querySelector('.settings-detail-body').append(content);
  content.hidden = false;
  cards.push(item);
  document.documentElement.classList.add('settings-open');
  card.classList.add('is-open');
  card.setAttribute('aria-expanded', 'true');
  card.setAttribute('aria-controls', dialog.id);
  dialog.querySelector('[data-settings-close]').onclick = () => closeCard(item);
  dialog.oncancel = (event) => {
    if (event.target !== dialog) return;
    event.preventDefault();
    closeCard(item);
  };
  dialog.addEventListener('close', () => restoreCard(item), { once: true });
  const outer = dialog === baseDialog;
  if (animate && outer) dialog.classList.add('is-moving');
  const travel = openDialog(dialog, {
    animate,
    from: outer
      ? () => {
          const from = card.getBoundingClientRect();
          const to = dialog.getBoundingClientRect();
          return (item.origin = {
            transform: `translate(${from.left - to.left}px, ${from.top - to.top}px) scale(${from.width / to.width}, ${from.height / to.height})`,
            opacity: 1,
            borderRadius:
              getComputedStyle(document.documentElement).getPropertyValue('--radius-24').trim() ||
              '0px',
          });
        }
      : undefined,
  });
  rememberHash();
  const reveal = setTimeout(
    () => {
      if (!item.closing) dialog.classList.remove('is-moving');
    },
    reducedMotion.matches ? 0 : 160,
  );
  await travel;
  clearTimeout(reveal);
  if (!item.closing) dialog.classList.remove('is-moving');
  if (!item.closing && cards.at(-1) === item)
    dialog.querySelector('[data-settings-close]').focus({ preventScroll: true });
}

document.addEventListener('click', (event) => {
  const card = event.target.closest?.('[data-settings-open]');
  if (card) openCard(card.dataset.settingsOpen);
});
document
  .querySelector('[data-settings-project]')
  ?.addEventListener('change', (event) => location.assign(event.target.value));
if (baseDialog && location.hash) openCard(decodeURIComponent(location.hash.slice(1)), false);
window.addEventListener('hashchange', () => {
  if (location.hash) openCard(decodeURIComponent(location.hash.slice(1)));
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
