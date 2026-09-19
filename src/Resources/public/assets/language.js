// The native select stays the control — it keeps the keyboard, the screen reader and the
// platform's own menu — and only the surrounding chip is styled. Choosing reloads, because
// every translated string on the page is rendered by the server.

for (const picker of document.querySelectorAll('[data-language-picker]')) {
  const select = picker.querySelector('select');
  if (!select) continue;
  select.addEventListener('change', async () => {
    const chosen = select.value;
    picker.dataset.busy = '1';
    try {
      const response = await fetch(picker.action, {
        method: 'POST',
        body: new FormData(picker),
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        // Put the control back on the language that is actually in force.
        select.value = select.dataset.current || select.value;
        delete picker.dataset.busy;

        return;
      }
      select.dataset.current = chosen;
      location.reload();
    } catch {
      delete picker.dataset.busy;
    }
  });
  select.dataset.current = select.value;
}
