const dialog = document.querySelector('#profile-dialog');

if (dialog) {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const status = dialog.querySelector('[data-profile-status]');
  const content = dialog.querySelector('[data-profile-content]');
  const retry = dialog.querySelector('[data-profile-retry]');
  const forms = [...dialog.querySelectorAll('[data-profile-form]')];
  const triggers = [...document.querySelectorAll('[data-profile-open]')];
  let opener;
  let busy = false;
  let closing = false;
  let loadController;

  function clearSecrets() {
    dialog.querySelectorAll('input[type="password"], input[name="code"]').forEach((input) => {
      input.value = '';
    });
  }

  function setPending(pending) {
    dialog.querySelector('[data-profile-email-request]').hidden = Boolean(pending);
    dialog.querySelector('[data-profile-email-pending]').hidden = !pending;
    if (!pending) return;
    dialog.querySelector('[data-profile-pending-email]').textContent = pending.email;
    const expires = new Date(pending.expires_at * 1000);
    const time = dialog.querySelector('[data-profile-expiry]');
    time.dateTime = expires.toISOString();
    time.textContent = expires.toLocaleTimeString(document.documentElement.lang, {
      hour: '2-digit',
      minute: '2-digit',
    });
    dialog.querySelector('input[name="request_id"]').value = pending.request_id;
  }

  async function loadProfile() {
    loadController?.abort();
    loadController = new AbortController();
    const controller = loadController;
    retry.hidden = true;
    status.hidden = false;
    status.textContent = 'Kontoeinstellungen werden geladen …';
    content.hidden = true;
    try {
      const response = await fetch('/profile', {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: controller.signal,
      });
      const result = await response.json();
      if (controller.signal.aborted || !dialog.open) return;
      if (!response.ok)
        throw new Error(result.message || 'Dein Profil konnte nicht geladen werden.');
      const profile = result.profile;
      dialog.querySelector('[data-profile-email]').textContent = profile.email;
      dialog.querySelector('[data-profile-local]').hidden = !profile.local_password;
      dialog.querySelector('[data-profile-external]').hidden = profile.local_password;
      dialog.querySelector('[data-profile-account-kind]').textContent = profile.local_password
        ? 'Lokales Konto'
        : 'Extern verwaltetes Konto';
      setPending(profile.pending);
      if (profile.pending) dialog.querySelector('[data-profile-email-section]').open = true;
      status.hidden = true;
      content.hidden = false;
    } catch (error) {
      if (error.name === 'AbortError') return;
      status.textContent = error.message || 'Dein Profil konnte nicht geladen werden.';
      retry.hidden = false;
    }
  }

  async function closeProfile() {
    if (closing || busy || !dialog.open) return;
    closing = true;
    loadController?.abort();
    if (!reducedMotion.matches) {
      await dialog.animate(
        [
          { opacity: 1, transform: 'translateY(0) scale(1)' },
          { opacity: 0, transform: 'translateY(8px) scale(.98)' },
        ],
        { duration: 150, easing: 'ease-in', fill: 'forwards' },
      ).finished;
    }
    dialog.close();
    dialog.getAnimations().forEach((animation) => animation.cancel());
    closing = false;
  }

  triggers.forEach((button) => {
    button.addEventListener('click', () => {
      if (dialog.open) return;
      opener = button;
      dialog.showModal();
      triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'true'));
      document.body.classList.add('profile-open');
      if (!reducedMotion.matches) {
        dialog.animate(
          [
            { opacity: 0, transform: 'translateY(12px) scale(.96)' },
            { opacity: 1, transform: 'translateY(0) scale(1)' },
          ],
          { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' },
        );
      }
      loadProfile();
    });
  });
  dialog.querySelector('[data-profile-close]').addEventListener('click', closeProfile);
  retry.addEventListener('click', loadProfile);
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeProfile();
  });
  let backdropPress = false;
  dialog.addEventListener('pointerdown', (event) => {
    const rect = dialog.getBoundingClientRect();
    backdropPress =
      event.target === dialog &&
      (event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom);
  });
  dialog.addEventListener('click', (event) => {
    if (backdropPress && event.target === dialog) closeProfile();
    backdropPress = false;
  });
  dialog.addEventListener('close', () => {
    triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
    document.body.classList.remove('profile-open');
    loadController?.abort();
    clearSecrets();
    forms.forEach((form) => form.reset());
    dialog.querySelectorAll('details').forEach((section) => {
      section.open = false;
    });
    dialog.querySelectorAll('[data-profile-error]').forEach((error) => {
      error.hidden = true;
    });
    opener?.focus();
  });

  forms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy) return;
      const errorBox = form.querySelector('[data-profile-error]');
      const body = new FormData(form);
      errorBox.hidden = true;
      busy = true;
      dialog.setAttribute('aria-busy', 'true');
      dialog.querySelectorAll('button').forEach((button) => {
        button.disabled = true;
      });
      clearSecrets();
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body,
          headers: {
            Accept: 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
          },
        });
        const result = await response.json();
        if (!response.ok)
          throw new Error(result.message || 'Die Änderung konnte nicht gespeichert werden.');
        if (result.url === '/login') {
          window.location.assign('/login');
          return;
        }
        setPending(result.pending);
        form.reset();
        const next = result.pending
          ? dialog.querySelector('input[name="code"]')
          : dialog.querySelector('[data-profile-email-request] input[name="email"]');
        next.focus();
      } catch (error) {
        errorBox.textContent =
          error.message ||
          'Verbindung unterbrochen. Bitte prüfe den aktuellen Stand vor einem erneuten Versuch.';
        errorBox.hidden = false;
        errorBox.focus();
      } finally {
        busy = false;
        dialog.removeAttribute('aria-busy');
        dialog.querySelectorAll('button').forEach((button) => {
          button.disabled = false;
        });
      }
    });
  });
}
