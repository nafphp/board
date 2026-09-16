const root = document.documentElement;
const mobileViewport = matchMedia('(max-width:760px)');
function syncSidebar() {
  const sidebar = document.querySelector('#sidebar');
  if (sidebar) sidebar.inert = mobileViewport.matches && !sidebar.classList.contains('open');
}
mobileViewport.addEventListener('change', syncSidebar);
syncSidebar();
const savedTheme = localStorage.getItem('nafinity.theme');
if (['dark', 'light', 'system'].includes(savedTheme)) root.dataset.theme = savedTheme;
export const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
let toastTimer;
export function toast(message) {
  const node = document.querySelector('#toast');
  if (!node) return;
  node.textContent = message;
  node.classList.add('visible');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => node.classList.remove('visible'), 6000);
}
function showError(form, message, errors = {}, conflict = false) {
  let box = form.querySelector('.form-errors');
  if (!box) {
    box = document.createElement('div');
    box.className = 'form-errors';
    form.prepend(box);
  }
  box.replaceChildren();
  const text = document.createElement('div');
  text.textContent = message;
  box.append(text);
  if (Object.keys(errors).length) {
    const list = document.createElement('ul');
    for (const [field, messages] of Object.entries(errors)) {
      for (const message of messages) {
        const item = document.createElement('li');
        item.textContent = message;
        list.append(item);
      }
      const input = form.elements.namedItem(field);
      if (input instanceof HTMLElement) input.setAttribute('aria-invalid', 'true');
    }
    box.append(list);
  }
  if (conflict) {
    const reload = document.createElement('a');
    reload.href = location.href;
    reload.textContent = 'Aktuellen Stand laden';
    box.append(reload);
  }
  box.classList.add('visible');
  box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
document.addEventListener('click', (event) => {
  const target = event.target.closest('button,a');
  if (!target) return;
  if (target.matches('.theme-toggle')) {
    const dark =
      root.dataset.theme === 'dark' ||
      (root.dataset.theme === 'system' && matchMedia('(prefers-color-scheme:dark)').matches);
    root.dataset.theme = dark ? 'light' : 'dark';
    localStorage.setItem('nafinity.theme', root.dataset.theme);
  }
  if (target.hasAttribute('data-sidebar-pin')) {
    root.dataset.sidebar = root.dataset.sidebar === 'pinned' ? 'rail' : 'pinned';
    localStorage.setItem('nafinity.sidebar', root.dataset.sidebar);
    // A mouse click would otherwise hold the panel open through :focus-within;
    // keyboard activation keeps the focus where the user is navigating.
    if (event.detail > 0) target.blur();
  }
  if (target.matches('.mobile-menu')) {
    const sidebar = document.querySelector('#sidebar');
    sidebar?.classList.toggle('open');
    target.setAttribute('aria-expanded', sidebar?.classList.contains('open') ? 'true' : 'false');
    syncSidebar();
    if (sidebar?.classList.contains('open')) sidebar.querySelector('a')?.focus();
  }
  if (target.dataset.dialog) {
    document.getElementById(target.dataset.dialog)?.showModal();
  }
  if (target.hasAttribute('data-close-dialog')) target.closest('dialog')?.close();
  if (target.hasAttribute('data-reload')) location.reload();
  if (target.dataset.demo) {
    const form = document.querySelector('.login-card form');
    form.elements.email.value = target.dataset.demo;
    form.elements.password.value = 'Nafinity-Demo-2026!';
    form.querySelector('button').focus();
  }
  if (target.hasAttribute('data-move-card')) {
    const card = target.closest('.ticket-card');
    const cell = card.closest('.board-cell');
    const board = card.closest('#board');
    const dialog = document.querySelector('#move-card');
    const form = dialog.querySelector('form');
    form.action = `/projects/${board.dataset.project}/tickets/${card.dataset.ticket}/move`;
    form.dataset.ticket = card.dataset.ticket;
    form.dataset.status = card.dataset.status || 'open';
    form.elements.version.value = card.dataset.version;
    form.elements.column_id.value = cell.dataset.column;
    form.elements.swimlane_id.value = cell.dataset.lane;
    dialog.querySelector('.move-title').textContent = card.dataset.title;
    dialog.showModal();
  }
});
document.addEventListener('submit', async (event) => {
  const form = event.target;
  if (!form.matches('form[data-enhanced]')) return;
  event.preventDefault();
  const submitter = event.submitter;
  const button = submitter || form.querySelector('button[type=submit],button:not([type])');
  const data = new FormData(form);
  if (submitter?.name) data.append(submitter.name, submitter.value);
  if (button) button.disabled = true;
  try {
    const response = await fetch(form.action, {
      method: (form.method || 'POST').toUpperCase(),
      body: data,
      headers: { Accept: 'application/json' },
    });
    const result = await response.json().catch(() => ({
      message:
        response.status === 401
          ? 'Bitte melde dich erneut an.'
          : 'Die Anfrage konnte nicht verarbeitet werden. Bitte lade die Seite neu.',
    }));
    if (!response.ok) {
      showError(
        form,
        result.message || 'Die Änderung konnte nicht gespeichert werden.',
        result.errors || {},
        response.status === 409,
      );
      return;
    }
    // The dialog move reloads the board, so the celebration is handed over to the next page.
    if (form.closest('#move-card')) {
      const column = form.elements.column_id.selectedOptions[0];
      if (column?.dataset.closes === '1' && form.dataset.status !== 'closed') {
        sessionStorage.setItem('nafinity.celebrate', form.dataset.ticket);
      }
    }
    if (form.action.endsWith('/preferences')) {
      localStorage.setItem('nafinity.theme', data.get('theme'));
    }
    if (form.closest('#settings-detail')) {
      const destination = new URL(result.url || location.href, location.href);
      if (destination.pathname.endsWith('/settings') || destination.pathname === '/preferences') {
        const card = form.closest('[data-settings-content]')?.dataset.settingsContent;
        destination.hash = card || '';
        location.assign(destination.href);
        if (destination.pathname === location.pathname) location.reload();
        return;
      }
    }
    if (result.url) location.assign(result.url);
    else location.reload();
  } catch {
    showError(form, 'Die Verbindung ist unterbrochen. Deine Eingaben bleiben erhalten.');
  } finally {
    if (button) button.disabled = false;
  }
});
const board = document.querySelector('#board');
document.addEventListener('nafinity:ai-changed', () => {
  const update = document.querySelector('#board-update');
  if (update) update.hidden = false;
});
if (board) {
  setInterval(async () => {
    if (document.hidden) return;
    try {
      const response = await fetch(`/projects/${board.dataset.project}/state`, {
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) return;
      const data = await response.json();
      if (String(data.revision) !== board.dataset.revision)
        document.querySelector('#board-update').hidden = false;
    } catch {}
  }, 30000);
}
const drawer = document.querySelector('#ticket-drawer');
let drawerAbort = null;
let drawerCloseFromHistory = false;
document.addEventListener('click', async (event) => {
  const link = event.target.closest('a[data-ticket-link]');
  if (
    !link ||
    !drawer ||
    event.metaKey ||
    event.ctrlKey ||
    event.shiftKey ||
    event.altKey ||
    event.button !== 0
  )
    return;
  event.preventDefault();
  drawerAbort?.abort();
  drawerAbort = new AbortController();
  drawer.querySelector('.drawer-content').textContent = 'Ticket wird geladen …';
  drawer.showModal();
  try {
    const response = await fetch(link.href + '?fragment=1', { signal: drawerAbort.signal });
    if (!response.ok) {
      location.assign(link.href);
      return;
    }
    drawer.querySelector('.drawer-content').innerHTML = await response.text();
    history.pushState({ nafinityDrawer: true }, '', link.href);
    drawer.querySelector('button[data-close-drawer]')?.focus();
  } catch (error) {
    if (error.name !== 'AbortError') {
      drawer.close();
      toast('Ticket konnte nicht geladen werden.');
    }
  }
});
document.addEventListener('click', (event) => {
  if (event.target.closest('[data-close-drawer]')) {
    event.preventDefault();
    drawer?.close();
  }
});
drawer?.addEventListener('close', () => {
  drawerAbort?.abort();
  if (!drawerCloseFromHistory && history.state?.nafinityDrawer) history.back();
  drawerCloseFromHistory = false;
});
window.addEventListener('popstate', () => {
  if (drawer?.open) {
    drawerCloseFromHistory = true;
    drawer.close();
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && mobileViewport.matches) {
    const sidebar = document.querySelector('#sidebar');
    if (sidebar?.classList.contains('open')) {
      sidebar.classList.remove('open');
      syncSidebar();
      const toggle = document.querySelector('.mobile-menu');
      toggle?.setAttribute('aria-expanded', 'false');
      toggle?.focus();
    }
  }
});

// The board brings its own drag, drop and celebration layer and is only needed there.
if (board) import('./board.js');
