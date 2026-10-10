import { t } from './i18n.js';

// Native datalist keeps keyboard selection and the direct-email fallback.
// Searches are bounded server-side and only available to member managers.
export function mountAccountSearch(input, { fetcher = fetch, delay = 250 } = {}) {
  const document = input.ownerDocument;
  const list = document.getElementById(input.getAttribute('list'));
  const status = input.closest('form').querySelector('[data-account-status]');
  let timer;
  let request;
  let generation = 0;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    request?.abort();
    const current = ++generation;
    const query = input.value.trim();
    list.replaceChildren();
    status.textContent = '';
    if (query.length < 2) return;
    timer = setTimeout(async () => {
      request = new AbortController();
      const url = new URL(input.dataset.accountSearch, document.baseURI);
      url.searchParams.set('q', query);
      try {
        const response = await fetcher(url, {
          headers: { Accept: 'application/json' },
          signal: request.signal,
        });
        if (!response.ok) throw new Error('Account search failed');
        const data = await response.json();
        if (current !== generation) return;
        for (const account of data.accounts || []) {
          const option = document.createElement('option');
          option.value = account.email;
          option.label = account.name;
          list.append(option);
        }
        status.textContent = list.children.length
          ? ''
          : t('Kein Konto gefunden. Du kannst die Person unten einladen.');
      } catch (error) {
        if (current !== generation || error.name === 'AbortError') return;
        status.textContent = t(
          'Die Kontosuche ist gerade nicht verfügbar. Du kannst die vollständige E-Mail-Adresse eingeben.',
        );
      }
    }, delay);
  });
}

export function mountMemberControls(document = globalThis.document) {
  document.querySelectorAll('[data-account-search]').forEach((input) => mountAccountSearch(input));
  document.querySelector('[data-copy-invitation]')?.addEventListener('click', async () => {
    const input = document.querySelector('#invitation-link');
    const status = document.querySelector('[data-copy-status]');
    try {
      await navigator.clipboard.writeText(input.value);
      status.textContent = t('Link kopiert.');
    } catch {
      input.select();
      status.textContent = t('Bitte kopiere den markierten Link.');
    }
  });
}
