/** Advanced mode is a view preference; hidden values must never be submitted. */
export function mountAdvancedSettings(root, storage) {
  const toggle = root.querySelector('[data-settings-advanced-toggle]');
  if (!toggle) return null;
  const key = toggle.dataset.settingsPreference;
  const apply = (enabled, remember = true) => {
    toggle.checked = enabled;
    toggle.setAttribute('aria-expanded', String(enabled));
    root.querySelectorAll('[data-settings-advanced-group]').forEach((group) => {
      group.hidden = !enabled;
    });
    root.querySelectorAll('[data-settings-advanced-fields]').forEach((fields) => {
      fields.hidden = !enabled;
      fields.disabled = !enabled;
    });
    if (remember) {
      try {
        storage?.setItem(key, enabled ? '1' : '0');
      } catch {}
    }
  };
  let saved = false;
  try {
    saved = storage?.getItem(key) === '1';
  } catch {}
  apply(saved, false);
  toggle.addEventListener('change', () => apply(toggle.checked));
  return { show: () => apply(true) };
}
