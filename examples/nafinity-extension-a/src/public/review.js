// The browser side of this extension's ticket widget.
//
// It is mounted once per widget node with that node as its root, and it returns
// the function that takes its own listener away again. It adds no second save
// path: the values it talks about are edited through Nafinity's own fields.

export function mount(root, context, api) {
  const heading = root.querySelector('h2');
  if (!heading) return undefined;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'text-button muted';
  button.textContent = 'Aktualisieren';
  button.addEventListener('click', onClick);
  heading.append(' ', button);

  async function onClick() {
    button.disabled = true;
    try {
      await api.refresh();
    } catch (failure) {
      api.toast(`Der Bericht konnte nicht geladen werden: ${failure.message}`);
    } finally {
      button.disabled = false;
    }
  }

  root.dataset.exampleMounted = String(context.ticketId ?? '');

  return () => {
    button.removeEventListener('click', onClick);
    button.remove();
    delete root.dataset.exampleMounted;
  };
}
