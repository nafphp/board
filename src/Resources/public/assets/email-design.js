import { t } from './i18n.js';

export function emailDraft(form) {
  const design = { common: {}, colors: {}, css: '' };
  const values = new FormData(form);
  for (const [key, value] of values) {
    const token = /^design\[(common|colors)\]\[([a-z-]+)\]$/.exec(key);
    if (token) design[token[1]][token[2]] = value;
    else if (key === 'design[css]') design.css = value;
  }
  return { design, sample: values.get('sample'), _csrf: values.get('_csrf') };
}

export function mountEmailDesigner(form) {
  const frame = form.querySelector('[data-email-preview]');
  const status = form.querySelector('[data-email-preview-status]');
  // The child allows no scripts. Reading its dimensions keeps the entire email
  // visible while the settings dialog supplies the single scroll container.
  function resize() {
    const height = frame.contentDocument?.body?.getBoundingClientRect().height;
    if (height > 0) frame.style.height = Math.min(1800, Math.max(360, Math.ceil(height))) + 'px';
  }
  frame.addEventListener('load', resize);
  if (globalThis.ResizeObserver) new ResizeObserver(resize).observe(frame);
  resize();
  let sequence = 0;
  let timer;
  let controller;
  async function preview() {
    const current = ++sequence;
    controller?.abort();
    controller = new AbortController();
    try {
      const draft = emailDraft(form);
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': draft._csrf,
        },
        body: JSON.stringify(draft),
        signal: controller.signal,
      });
      const result = await response.json();
      if (current !== sequence) return;
      if (!response.ok) {
        status.textContent = result.message || t('Die Vorschau konnte nicht aktualisiert werden.');
        return;
      }
      frame.srcdoc = result.html;
      status.textContent = t('Vorschau aktualisiert. Änderungen gelten nach dem Speichern.');
    } catch (error) {
      if (error.name !== 'AbortError' && current === sequence) {
        status.textContent = t('Die Vorschau konnte nicht aktualisiert werden.');
      }
    }
  }
  function schedule() {
    frame.dataset.size = form.elements.namedItem('preview_size').value;
    ++sequence;
    controller?.abort();
    clearTimeout(timer);
    timer = setTimeout(preview, 200);
  }
  form.addEventListener('input', schedule);
  form.addEventListener('change', schedule);
}
