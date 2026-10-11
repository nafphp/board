import { t } from './i18n.js';
import { mountIsolatedPreview } from './isolated-preview.js';
import { enhanceChoices } from './choice.js';

export function themeDraft(form) {
  const draft = { common: {}, light: {}, dark: {} };
  for (const [key, value] of new FormData(form)) {
    const token = /^(common|light|dark)\[([a-z-]+)\]$/.exec(key);
    if (token) draft[token[1]][token[2]] = value;
    else if (['name', 'base', 'css', '_csrf'].includes(key)) draft[key] = value;
  }
  draft.enabled = form.querySelector('[name="enabled"][type="checkbox"]').checked ? '1' : '0';
  return draft;
}

export function mountThemeManager(root) {
  const { schema, themes } = JSON.parse(root.querySelector('[data-theme-definitions]').textContent);
  const form = root.querySelector('[data-theme-editor]');
  const picker = root.querySelector('[name="edit_theme"]');
  const frame = root.querySelector('[data-theme-frame]');
  const status = root.querySelector('[data-theme-preview-status]');
  const resize = mountIsolatedPreview(frame);
  let css = '';
  let sequence = 0;
  let timer;
  let controller;
  let importSequence = 0;
  const field = (name) =>
    name === 'enabled'
      ? form.querySelector('[name="enabled"][type="checkbox"]')
      : form.elements.namedItem(name);

  function render() {
    const document = frame.contentDocument;
    if (!document?.getElementById('theme-preview-style')) return;
    document.documentElement.dataset.theme = field('preview_mode').value;
    document.getElementById('theme-preview-style').textContent = css;
    resize();
  }

  async function preview() {
    const current = ++sequence;
    controller?.abort();
    controller = new AbortController();
    try {
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': field('_csrf').value,
        },
        body: JSON.stringify(themeDraft(form)),
        signal: controller.signal,
      });
      const result = await response.json();
      if (current !== sequence) return;
      if (!response.ok) {
        status.textContent = result.message || t('Die Vorschau konnte nicht aktualisiert werden.');
        return;
      }
      css = result.css;
      render();
      status.textContent = t('Vorschau aktualisiert. Änderungen gelten nach dem Speichern.');
    } catch (error) {
      if (error.name !== 'AbortError' && current === sequence) {
        status.textContent = t('Die Vorschau konnte nicht aktualisiert werden.');
      }
    }
  }

  function schedule() {
    ++sequence;
    controller?.abort();
    clearTimeout(timer);
    timer = setTimeout(preview, 200);
  }

  function choose(theme, id = '') {
    field('id').value = id;
    field('name').value = theme.name;
    field('base').value = theme.base;
    field('enabled').checked = theme.enabled;
    field('css').value = theme.css || '';
    for (const [key, definition] of Object.entries(schema.common)) {
      field('common[' + key + ']').value = theme.common?.[key] ?? definition.default;
    }
    for (const mode of ['light', 'dark']) {
      for (const [key, value] of Object.entries(schema.palettes[theme.base][mode])) {
        const input = field(mode + '[' + key + ']');
        if (input) input.value = theme[mode]?.[key] ?? value;
      }
    }
    enhanceChoices(form);
    for (const color of form.querySelectorAll('.field-custom')) {
      color.dispatchEvent(new Event('input', { bubbles: true }));
    }
    schedule();
  }

  picker.addEventListener('change', () => {
    ++importSequence;
    const id = picker.value;
    choose(
      id === 'new' ? { name: t('Mein Theme'), base: 'classic', enabled: true } : themes[id],
      id.startsWith('theme-') ? id : '',
    );
  });
  root.querySelector('[data-theme-duplicate]').addEventListener('click', () => {
    field('id').value = '';
    field('name').value += ' ' + t('Kopie');
    schedule();
  });
  root.querySelector('[data-theme-import]').addEventListener('change', async (event) => {
    const current = ++importSequence;
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      if (file.size > 65536) throw new Error();
      const imported = JSON.parse(await file.text());
      if (current !== importSequence) return;
      if (imported.version !== 1 || !imported.theme || !schema.palettes[imported.theme.base]) {
        throw new Error();
      }
      // Server compilation validates the complete document before any form state changes.
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': field('_csrf').value,
        },
        body: JSON.stringify({
          ...imported.theme,
          enabled: imported.theme.enabled ? '1' : '0',
          _csrf: field('_csrf').value,
        }),
      });
      const result = await response.json();
      if (current !== importSequence) return;
      if (!response.ok) throw new Error(result.message);
      choose(imported.theme);
      css = result.css;
      render();
    } catch (error) {
      if (current === importSequence)
        status.textContent = error.message || t('Dieses Theme konnte nicht importiert werden.');
    } finally {
      event.target.value = '';
    }
  });
  form.addEventListener('input', () => {
    ++importSequence;
    schedule();
  });
  form.addEventListener('change', (event) => {
    if (event.target === field('preview_mode')) render();
    else {
      if (event.target === field('base')) {
        for (const mode of ['light', 'dark']) {
          for (const [key, value] of Object.entries(schema.palettes[field('base').value][mode])) {
            const input = field(mode + '[' + key + ']');
            if (input) {
              input.value = value;
              input.dispatchEvent(new Event('input', { bubbles: true }));
            }
          }
        }
      }
      ++importSequence;
      schedule();
    }
  });
  frame.addEventListener('load', render);
  schedule();
}
