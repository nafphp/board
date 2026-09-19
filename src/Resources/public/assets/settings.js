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

function animateCard(opening) {
  animation?.cancel();
  const small = cardTransform();
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
    { duration: reducedMotion.matches ? 0 : 360, easing: 'cubic-bezier(.22,1,.36,1)' },
  );
  return animation.finished.catch(() => {});
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
  dialog.showModal();
  card.classList.add('is-open');
  card.setAttribute('aria-expanded', 'true');
  history.replaceState(history.state, '', `#${id}`);
  if (animate) {
    dialog.classList.add('is-moving');
    await animateCard(true);
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
