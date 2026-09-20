// The picker is the shared select, so the keyboard and the screen reader are handled
// there and this only carries the choice to the server. Choosing reloads, because every
// translated string on the page is rendered by the server.
import { enhanceChoices } from './choice.js';

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
        // Put the control back on the language that is actually in force -- both the
        // native value and the label the picker is showing for it.
        select.value = select.dataset.current || select.value;
        enhanceChoices(picker);
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
