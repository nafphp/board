import { t } from './i18n.js';

// Cancel and invalidate together: a late answer must not repopulate a cleared or closed popup.
export function createSearchLoader(endpoint, update, { fetcher = fetch, delay = 180 } = {}) {
  let timer;
  let request;
  let generation = 0;
  function cancel() {
    clearTimeout(timer);
    request?.abort();
    generation++;
  }
  return {
    cancel,
    search(query) {
      cancel();
      query = query.trim();
      if ([...query].length < 2) {
        update({ state: 'short' });
        return;
      }
      const current = generation;
      update({ state: 'loading' });
      timer = setTimeout(async () => {
        request = new AbortController();
        const url = new URL(endpoint);
        url.searchParams.set('q', query);
        try {
          const response = await fetcher(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: request.signal,
          });
          if (!response.ok || response.redirected) throw new Error('Workspace search failed');
          const data = await response.json();
          if (current === generation) update({ state: 'ready', ...data });
        } catch (error) {
          if (current === generation && error.name !== 'AbortError') update({ state: 'error' });
        }
      }, delay);
    },
  };
}

export function mountWorkspaceSearch(root, options = {}) {
  const document = root.ownerDocument;
  const input = root.querySelector('[data-workspace-query]');
  const popup = root.querySelector('[data-search-popup]');
  const list = root.querySelector('[data-search-results]');
  const status = root.querySelector('[data-search-status]');
  const all = root.querySelector('[data-search-all]');
  let selected = -1;
  let items = [];
  let dismissed = false;

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', list.id);
  input.setAttribute('aria-expanded', 'false');

  function select(index) {
    selected = index;
    items.forEach((item, i) => item.setAttribute('aria-selected', String(i === index)));
    if (items[index]) {
      input.setAttribute('aria-activedescendant', items[index].id);
      items[index].scrollIntoView({ block: 'nearest' });
    } else input.removeAttribute('aria-activedescendant');
  }
  function close() {
    loader.cancel();
    dismissed = true;
    popup.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    select(-1);
  }
  const loader = createSearchLoader(
    new URL(root.dataset.endpoint, document.baseURI),
    (data) => {
      list.replaceChildren();
      items = [];
      select(-1);
      if (data.state === 'short' || dismissed) {
        popup.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        return;
      }
      popup.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      const url = new URL(root.querySelector('form').action);
      url.searchParams.set('q', input.value.trim());
      all.href = url.href;
      status.textContent =
        data.state === 'loading'
          ? t('Suche läuft …')
          : data.state === 'error'
            ? t('Die Suche konnte nicht geladen werden. Bitte erneut versuchen.')
            : data.count
              ? t(':count Vorschläge', { count: data.count })
              : t('Keine passenden Tickets oder Boards gefunden.');
      all.hidden = data.state !== 'ready';
      if (data.state === 'ready') {
        // Markup is rendered and escaped by our own search/results template.
        list.innerHTML = data.html;
        items = [...list.querySelectorAll('[data-search-result]')];
      }
    },
    options,
  );

  function search() {
    dismissed = false;
    loader.search(input.value);
  }
  input.addEventListener('input', search);
  input.addEventListener('focus', search);
  input.addEventListener('keydown', (event) => {
    if (event.isComposing) return;
    if (event.key === 'Escape') {
      if (!popup.hidden) {
        event.preventDefault();
        event.stopPropagation();
        close();
      }
    } else if (['ArrowDown', 'ArrowUp'].includes(event.key) && !popup.hidden && items.length) {
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      select(
        selected < 0
          ? direction === 1
            ? 0
            : items.length - 1
          : (selected + direction + items.length) % items.length,
      );
    } else if (event.key === 'Enter' && !popup.hidden && items[selected]) {
      event.preventDefault();
      items[selected].click();
    }
  });
  root.addEventListener('focusout', (event) => {
    if (!root.contains(event.relatedTarget)) close();
  });
  root.addEventListener('click', (event) => {
    if (event.target.closest('a')) close();
  });
  document.addEventListener('pointerdown', (event) => {
    if (!root.contains(event.target)) close();
  });
}

if (typeof document !== 'undefined') {
  document
    .querySelectorAll('[data-workspace-search]')
    .forEach((root) => mountWorkspaceSearch(root));
}
