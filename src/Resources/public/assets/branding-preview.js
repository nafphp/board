import { t } from './i18n.js';

const invalidLogo =
  'Bitte wähle ein PNG-, JPG- oder WebP-Bild mit höchstens 512 KB und 2048 × 2048 Pixeln.';

// Read only this bounded local file. Nothing is uploaded before the form is saved.
export function readLogo(file) {
  return new Promise((resolve, reject) => {
    const fail = () => reject(new Error(invalidLogo));
    if (
      !['image/png', 'image/jpeg', 'image/webp'].includes(file.type) ||
      file.size < 1 ||
      file.size > 524288
    ) {
      fail();
      return;
    }
    const reader = new FileReader();
    reader.onerror = fail;
    reader.onabort = fail;
    reader.onload = () => {
      const image = new Image();
      image.onerror = fail;
      image.onload = () => {
        if (
          image.naturalWidth < 1 ||
          image.naturalHeight < 1 ||
          image.naturalWidth > 2048 ||
          image.naturalHeight > 2048
        ) {
          fail();
          return;
        }
        resolve(reader.result);
      };
      image.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

export function mountBrandingPreview(form, { readImage = readLogo } = {}) {
  const brand = form.querySelector('.branding-preview .brand');
  if (!brand) return;
  const name = form.elements.namedItem('name');
  const file = form.elements.namedItem('logo');
  const remove = form.elements.namedItem('remove_logo');
  const font = form.elements.namedItem('font');
  const mode = form.elements.namedItem('color_mode');
  const color = form.elements.namedItem('color');
  const image = brand.querySelector('.brand-image');
  const symbol = brand.querySelector('.brand-symbol');
  const word = brand.querySelector('.brand-word');
  const error = form.querySelector('[data-branding-preview-error]');
  const colorField = form.querySelector('[data-branding-color]');
  const savedLogo = image.getAttribute('src');
  let selectedLogo = null;
  let generation = 0;

  function render() {
    word.textContent = name.value.trim() || t('Name der Anwendung');
    brand.title = word.textContent;
    brand.dataset.brandFont = font.value;
    const custom = mode.value === 'custom';
    colorField.hidden = !custom;
    brand.style.setProperty('--brand-color', custom ? color.value : 'inherit');
    const logo = remove?.checked ? null : selectedLogo || savedLogo;
    image.hidden = !logo;
    symbol.hidden = !!logo;
    if (logo) image.setAttribute('src', logo);
    else image.removeAttribute('src');
  }

  function clearError() {
    error.hidden = true;
    error.textContent = '';
    file.setCustomValidity('');
  }

  form.addEventListener('input', render);
  form.addEventListener('change', async (event) => {
    if (event.target === file) {
      const current = ++generation;
      selectedLogo = null;
      clearError();
      render();
      const chosen = file.files?.[0];
      if (!chosen) return;
      if (remove) remove.checked = false;
      file.setCustomValidity(t('Das Logo wird geprüft.'));
      try {
        const result = await readImage(chosen);
        if (current !== generation) return;
        selectedLogo = result;
        clearError();
      } catch {
        if (current !== generation) return;
        error.textContent = t(invalidLogo);
        error.hidden = false;
        file.setCustomValidity(t(invalidLogo));
      }
    } else if (event.target === remove && remove.checked) {
      ++generation;
      selectedLogo = null;
      file.value = '';
      clearError();
    }
    render();
  });
  form.addEventListener('reset', () => {
    ++generation;
    selectedLogo = null;
    clearError();
    queueMicrotask(render);
  });
  render();
}
