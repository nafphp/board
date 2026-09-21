/*
 * The history, while it is being written.
 *
 * The socket says that this project changed and deliberately says nothing about
 * what -- so this asks, the same way the board does: through the application's
 * ordinary authorised path, naming the last entry it already holds. What comes
 * back is rendered by the same template the page used, because an entry that
 * arrives live should be the entry the page would have drawn.
 *
 * Asking for everything after a known id rather than for the newest handful is
 * what keeps two people watching at once from seeing each other's duplicates,
 * and what lets a page that was away for a while catch up in one request.
 */
const list = document.querySelector('[data-activity]');

if (list) {
  const arriving = new Set();
  let asking = false;

  async function catchUp() {
    // One request at a time: a burst of changes is one thing to ask about, not
    // one question per change.
    if (asking) return;
    asking = true;
    try {
      const url = `${list.dataset.entries}?after=${encodeURIComponent(list.dataset.latest || '0')}`;
      const response = await fetch(url, { headers: { Accept: 'application/json' } }).catch(
        () => null,
      );
      if (!response?.ok || response.redirected) return;

      let data;
      try {
        data = await response.json();
      } catch {
        return;
      }
      for (const [id, markup] of Object.entries(data?.entries ?? {})) place(id, markup);
    } finally {
      asking = false;
    }
  }

  function place(id, markup) {
    if (list.querySelector(`[data-activity-entry="${CSS.escape(id)}"]`)) return;

    const shell = document.createElement('template');
    shell.innerHTML = markup.trim();
    const entry = shell.content.querySelector('[data-activity-entry]');
    if (!entry) return;

    // Oldest first, each above the last, so the newest ends up on top.
    list.prepend(entry);
    list.dataset.latest = String(Math.max(Number(list.dataset.latest || 0), Number(id)));
    arriving.add(entry);
    entry.dataset.arrived = '';
  }

  // Said once, like the cards on the board.
  list.addEventListener('animationend', (event) => {
    if (event.animationName !== 'entry-arrive') return;
    delete event.target.dataset.arrived;
    arriving.delete(event.target);
  });

  document.addEventListener('naf:websocket-message', (event) => {
    if (event.detail?.channel !== `project:${list.dataset.project}`) return;
    catchUp();
  });
}
