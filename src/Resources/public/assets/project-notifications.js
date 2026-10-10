import { t } from './i18n.js';

export function projectNotificationSettings(form) {
  const input = form.querySelector('input[role="switch"]');
  const state = form.querySelector('[data-project-notification-state]');
  const feedback = form.querySelector('[data-project-notification-feedback]');
  const errorBox = form.querySelector('.form-errors');
  let saved = input.checked;
  let busy = false;
  form.querySelector('button[type="submit"]').hidden = true;

  form.addEventListener('change', (event) => {
    if (event.target === input) form.requestSubmit();
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    input.disabled = true;
    form.setAttribute('aria-busy', 'true');
    errorBox.classList.remove('visible');
    feedback.textContent = t('Wird gespeichert …');
    let message = t(
      'Verbindung unterbrochen. Bitte prüfe den aktuellen Stand vor einem erneuten Versuch.',
    );
    try {
      const response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': form.querySelector('input[name="_csrf"]').value,
        },
        body: JSON.stringify({ values: { muted: input.checked } }),
      });
      const result = await response.json();
      if (!response.ok || typeof result.values?.muted !== 'boolean') {
        message = result.message || t('Die Änderung konnte nicht gespeichert werden.');
        throw new Error(message);
      }
      saved = result.values.muted;
      input.checked = saved;
      input.defaultChecked = saved;
      state.textContent = t(saved ? 'Stummgeschaltet' : 'Benachrichtigungen aktiv');
      feedback.textContent = t('Gespeichert');
    } catch {
      input.checked = saved;
      feedback.textContent = '';
      errorBox.textContent = message;
      errorBox.classList.add('visible');
    } finally {
      busy = false;
      input.disabled = false;
      form.removeAttribute('aria-busy');
    }
  });
}
