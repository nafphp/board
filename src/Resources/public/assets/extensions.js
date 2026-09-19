// The browser side of a contribution.
//
// A contributed node that names a module gets that module mounted once, with
// the node itself as its root — never the whole page. The module's URL comes
// from the server-side definition that rendered the node, never from a ticket
// or a setting value, so nothing a person typed can decide what is imported.
//
// A module that fails to import says so where it would have appeared. The
// fixed parts of a ticket — its title, its description, its comments — are not
// affected by that, because they are not contributions.

import { toast } from './app.js';
import { refresh, saveTicket } from './ticket.js';

const mounted = new WeakMap();

function context(root) {
  const workspace = root.closest('[data-ticket-url]');

  return Object.freeze({
    id: root.dataset.extensionId ?? null,
    mode: workspace?.dataset.mode ?? 'page',
    projectId: workspace?.dataset.project ? Number(workspace.dataset.project) : null,
    ticketId: workspace?.dataset.ticket ? Number(workspace.dataset.ticket) : null,
    version: workspace?.dataset.version ? Number(workspace.dataset.version) : null,
  });
}

function api(root) {
  const workspace = root.closest('.ticket-workspace');

  return Object.freeze({
    toast,
    // The existing ticket write queue, not a second one.
    save: (form) => (workspace && form ? saveTicket(form) : Promise.resolve(false)),
    refresh: () => (workspace ? refresh(workspace, null, null) : Promise.resolve()),
  });
}

function report(root, message) {
  const note = document.createElement('p');
  note.className = 'muted small extension-error';
  note.setAttribute('role', 'status');
  note.textContent = message;
  root.append(note);
}

async function mount(root) {
  if (mounted.has(root)) return;
  const source = root.dataset.extensionModule;
  if (!source) return;
  mounted.set(root, null);
  try {
    const module = await import(source);
    if (typeof module.mount !== 'function') {
      throw new Error('Das Modul stellt kein mount(root, context, api) bereit.');
    }
    const dispose = await module.mount(root, context(root), api(root));
    mounted.set(root, typeof dispose === 'function' ? dispose : null);
  } catch (failure) {
    mounted.delete(root);
    report(root, `Dieser Beitrag konnte nicht geladen werden: ${failure.message}`);
  }
}

function dispose(root) {
  const teardown = mounted.get(root);
  mounted.delete(root);
  if (typeof teardown === 'function') {
    try {
      teardown();
    } catch {
      // A module that fails while going away must not keep the page from updating.
    }
  }
}

export function mountWithin(node) {
  if (!node) return;
  if (node.dataset?.extensionModule) mount(node);
  node.querySelectorAll?.('[data-extension-module]').forEach(mount);
}

export function disposeWithin(node) {
  if (!node) return;
  if (node.dataset?.extensionModule) dispose(node);
  node.querySelectorAll?.('[data-extension-module]').forEach(dispose);
}

document.addEventListener('nafinity:fragment-removing', (event) => {
  disposeWithin(event.detail.node);
});
document.addEventListener('nafinity:fragment-updated', (event) => {
  mountWithin(event.detail.container);
});
document.addEventListener('nafinity:drawer-closed', (event) => {
  disposeWithin(event.detail?.node ?? document.getElementById('ticket-drawer'));
});
document.addEventListener('DOMContentLoaded', () => mountWithin(document.body));
if (document.readyState !== 'loading') mountWithin(document.body);
