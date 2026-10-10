import { t } from './i18n.js';

// Selection always means the explicit, visible rows. A new result page resets it.
export function selectedTargets(root) {
  return [...root.querySelectorAll('[data-bulk-target]:checked:not(:disabled)')].map(
    (input) => input.value,
  );
}

export function syncSelection(root) {
  const count = selectedTargets(root).length;
  const available = root.querySelectorAll('[data-bulk-target]:not(:disabled)').length;
  const all = root.querySelector('[data-bulk-all]');
  if (all) {
    all.checked = available > 0 && count === available;
    all.indeterminate = count > 0 && count < available;
    all.disabled = available === 0;
  }
  root.querySelector('[data-bulk-bar]').hidden = count === 0;
  root.querySelector('[data-bulk-count]').textContent = t(':count ausgewählt', { count });
  root.querySelectorAll('[data-person-row]').forEach((row) => {
    row.classList.toggle('is-selected', !!row.querySelector('[data-bulk-target]')?.checked);
  });
  return count;
}

export function mountPeopleDirectory(root) {
  const document = root.ownerDocument;
  const error = root.querySelector('[data-people-error]');
  const editor = root.querySelector('#people-editor');
  const content = root.querySelector('[data-person-content]');
  const bulkForm = root.querySelector('[data-bulk-form]');
  let generation = 0;
  let controller;
  let loadingPerson = false;

  const open = (dialog, trigger) => {
    dialog.addEventListener('close', () => trigger?.focus({ preventScroll: true }), { once: true });
    dialog.showModal();
  };

  async function loadResults(url) {
    controller?.abort();
    controller = new AbortController();
    const current = ++generation;
    error.textContent = '';
    root.querySelector('[data-people-results]').setAttribute('aria-busy', 'true');
    try {
      const endpoint = new URL(root.dataset.peopleUrl, document.baseURI);
      endpoint.search = url.search;
      const response = await fetch(endpoint, {
        headers: { Accept: 'text/html' },
        signal: controller.signal,
      });
      if (!response.ok || response.redirected) throw new Error('Directory unavailable');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (current !== generation) return;
      const fresh = parsed.querySelector('[data-people-results]');
      if (!fresh) throw new Error('Missing directory');
      root.querySelector('[data-people-results]').replaceWith(fresh);
      syncSelection(root);
      history.replaceState(history.state, '', url);
    } catch (failure) {
      if (current === generation && failure.name !== 'AbortError') {
        error.textContent = t(
          'Die Nutzerliste konnte nicht geladen werden. Bitte erneut versuchen.',
        );
      }
    } finally {
      if (current === generation)
        root.querySelector('[data-people-results]').removeAttribute('aria-busy');
    }
  }

  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-bulk-all]')) {
      root.querySelectorAll('[data-bulk-target]:not(:disabled)').forEach((input) => {
        input.checked = event.target.checked;
      });
    }
    if (event.target.matches('[data-bulk-target], [data-bulk-all]')) syncSelection(root);
    if (event.target.matches('[data-bulk-enable]')) {
      event.target.closest('.settings-bulk-property').querySelector('fieldset').disabled =
        !event.target.checked;
      root.querySelector('[data-bulk-submit]').disabled = !root.querySelector(
        '[data-bulk-enable]:checked',
      );
    }
  });

  root.addEventListener('click', async (event) => {
    const close = event.target.closest('[data-people-close]');
    if (close) {
      close.closest('dialog').close();
      return;
    }
    const clear = event.target.closest('[data-bulk-clear]');
    if (clear) {
      root.querySelectorAll('[data-bulk-target]').forEach((input) => (input.checked = false));
      syncSelection(root);
      return;
    }
    const page = event.target.closest('[data-people-page]');
    if (page) {
      event.preventDefault();
      await loadResults(new URL(page.href));
      return;
    }
    const trigger = event.target.closest('[data-people-dialog]');
    if (trigger) {
      const dialog = root.querySelector(`#${trigger.dataset.peopleDialog}`);
      if (dialog.id === 'people-bulk') {
        const targets = selectedTargets(root);
        if (!targets.length) return;
        bulkForm.reset();
        const { refreshChoices } = await import('./choice.js');
        refreshChoices(bulkForm);
        bulkForm.querySelectorAll('fieldset').forEach((field) => (field.disabled = true));
        bulkForm.querySelector('[data-bulk-submit]').disabled = true;
        bulkForm.querySelector('.form-errors').replaceChildren();
        const inputs = targets.map((id) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'targets[]';
          input.value = id;
          return input;
        });
        bulkForm.querySelector('[data-bulk-targets]').replaceChildren(...inputs);
        root.querySelector('[data-bulk-summary]').textContent = t(
          'Änderungen gelten für :count ausgewählte Konten.',
          { count: targets.length },
        );
      }
      open(dialog, trigger);
      return;
    }
    let person = event.target.closest('[data-person-open]');
    if (!person && !event.target.closest('button, input, a, label, select')) {
      person = event.target.closest('[data-person-row]')?.querySelector('[data-person-open]');
    }
    if (!person || loadingPerson) return;
    loadingPerson = true;
    error.textContent = '';
    person.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(person.dataset.personOpen, { headers: { Accept: 'text/html' } });
      if (!response.ok || response.redirected) throw new Error('Person unavailable');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (!parsed.querySelector('[data-person-title]')) throw new Error('Missing person');
      content.replaceChildren(...parsed.body.childNodes);
      document.dispatchEvent(
        new CustomEvent('nafinity:fragment-updated', { detail: { container: content } }),
      );
      if (root.closest('#settings-detail')?.open) open(editor, person);
    } catch {
      error.textContent = t('Das Konto konnte nicht geladen werden. Bitte erneut versuchen.');
    } finally {
      person.removeAttribute('aria-busy');
      loadingPerson = false;
    }
  });

  root.querySelector('[data-people-search]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const url = new URL('/settings#installation_users', document.baseURI);
    url.searchParams.set('users_q', new FormData(event.target).get('users_q'));
    await loadResults(url);
  });
  syncSelection(root);
}
