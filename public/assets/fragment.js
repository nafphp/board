// One way to reload a ticket fragment, shared by the inline editor and the upload form.
//
// Both used to fetch the same URL and then reach for the one section they cared
// about. A widget that was added or removed server-side needs more than that:
// walking the sections currently on the page can only ever find the ones that
// are already there, so the fresh document decides which widgets exist.

export async function fetchWorkspace(workspace) {
  const response = await fetch(`${workspace.dataset.ticketUrl}?fragment=1`, {
    headers: { Accept: 'text/html' },
  });
  if (!response.ok || response.redirected) return null;
  const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');

  return parsed.querySelector('.ticket-workspace');
}

// A dirty editor keeps its draft: the node stays, only its read-only parts and
// the version fields are brought up to date by the caller.
export function syncWidgets(workspace, fresh, { keep = () => false } = {}) {
  const container = workspace.querySelector('[data-widget-slot]');
  const source = fresh.querySelector('[data-widget-slot]');
  if (!container || !source) return;

  const freshWidgets = [...source.querySelectorAll(':scope > [data-extension-id]')];
  const freshIds = new Set(freshWidgets.map((node) => node.dataset.extensionId));

  container.querySelectorAll(':scope > [data-extension-id]').forEach((current) => {
    if (!freshIds.has(current.dataset.extensionId)) {
      disposeWithin(current);
      current.remove();
    }
  });

  let previous = null;
  for (const replacement of freshWidgets) {
    const id = replacement.dataset.extensionId;
    const current = container.querySelector(`:scope > [data-extension-id="${attribute(id)}"]`);
    if (current && keep(current)) {
      previous = current;
      continue;
    }
    if (current) {
      if (current.tagName === 'DETAILS' && current.open) replacement.open = true;
      disposeWithin(current);
      current.replaceWith(replacement);
    } else if (previous) {
      previous.after(replacement);
    } else {
      container.prepend(replacement);
    }
    previous = replacement;
  }

  document.dispatchEvent(new CustomEvent('nafinity:fragment-updated', { detail: { container } }));
}

export function attribute(value) {
  return String(value).replace(/["\\]/g, '\\$&');
}

function disposeWithin(node) {
  document.dispatchEvent(new CustomEvent('nafinity:fragment-removing', { detail: { node } }));
}
