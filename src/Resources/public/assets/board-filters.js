import { t } from './i18n.js';

// The server owns the grammar and translation. Returning to dropdowns applies
// the translated query so their values, cards and the saved URL stay in agreement.
export function mountBoardFilters(
  root,
  { request = fetch, navigate = (url) => location.assign(url), enhance = () => {} } = {},
) {
  if (!root) return;
  const simple = root.querySelector('[data-simple-filters]');
  const advanced = root.querySelector('[data-advanced-filters]');
  const expression = advanced.elements.namedItem('dql');
  const error = root.querySelector('[data-filter-error]');
  const buttons = [...root.querySelectorAll('[data-filter-mode], [data-dql-apply]')];
  let busy = false;

  const parameters = (form) => {
    const result = new URLSearchParams(new FormData(form));
    for (const [key, value] of [...result]) if (value === '') result.delete(key);
    return result;
  };
  const fail = (message) => {
    error.textContent = message;
    error.hidden = false;
    if (root.dataset.mode === 'advanced') {
      expression.setAttribute('aria-invalid', 'true');
      expression.focus();
    }
  };
  const retained = (form, values) => {
    const target = form.querySelector('[data-retained-filters]');
    target.replaceChildren();
    const append = (name, value) => {
      if (value !== null && typeof value === 'object') {
        for (const [key, entry] of Object.entries(value)) append(`${name}[${key}]`, entry);
        return;
      }
      const input = root.ownerDocument.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? '');
      target.append(input);
    };
    for (const [name, value] of Object.entries(values)) append(name, value);
  };
  const translate = async (form, done) => {
    if (busy) return;
    busy = true;
    root.setAttribute('aria-busy', 'true');
    buttons.forEach((button) => (button.disabled = true));
    const asked = parameters(form).toString();
    let message = t('Die Filter konnten nicht geladen werden.');
    try {
      const response = await request(`${root.dataset.endpoint}?${asked}`, {
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok) {
        message = result.message || t('Bitte prüfe deine Filter.');
        throw new Error(message);
      }
      if (parameters(form).toString() !== asked) {
        message = t('Die Filter wurden inzwischen geändert. Bitte versuche es erneut.');
        throw new Error(message);
      }
      error.hidden = true;
      expression.removeAttribute('aria-invalid');
      done(result, asked);
    } catch {
      fail(message);
    } finally {
      busy = false;
      root.removeAttribute('aria-busy');
      buttons.forEach((button) => (button.disabled = false));
    }
  };
  root.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-filter-mode]');
    if (!button) return;
    event.preventDefault();
    const mode = button.dataset.filterMode;
    const form = root.dataset.mode === 'advanced' ? advanced : simple;
    translate(form, (result) => {
      if (mode === 'advanced') {
        expression.value = result.expression;
        retained(advanced, result.advancedRetained);
        const summary = advanced.querySelector('[data-advanced-retained-summary]');
        summary.textContent = result.retainedSummary || '';
        summary.hidden = !result.retainedSummary;
      } else {
        simple.querySelector('[data-simple-controls]').innerHTML = result.controls;
        retained(simple, result.simpleRetained);
        simple.querySelector('[data-residual-query]').value = result.residual;
        simple.querySelector('[data-residual-text]').textContent = result.residual;
        simple.querySelector('[data-residual-summary]').hidden = !result.residual;
        enhance(simple);
      }
      simple.hidden = mode !== 'simple';
      advanced.hidden = mode !== 'advanced';
      root.dataset.mode = mode;
      const focus =
        mode === 'advanced'
          ? expression
          : simple.querySelector('button, select, input:not([type="hidden"])');
      focus?.focus();
      if (mode === 'simple') {
        const query = parameters(simple).toString();
        navigate(`${simple.action}${query ? `?${query}` : ''}`);
      }
    });
  });
  advanced.addEventListener('submit', (event) => {
    event.preventDefault();
    translate(advanced, (_result, asked) =>
      navigate(`${advanced.action}${asked ? `?${asked}` : ''}`),
    );
  });
  expression.addEventListener('input', () => {
    error.hidden = true;
    expression.removeAttribute('aria-invalid');
  });
}

const root = globalThis.document?.querySelector('[data-board-filters]');
if (root) {
  Promise.all([import('./choice.js'), import('./dialog.js')]).then(([choices, dialogs]) => {
    dialogs.mountDialogEditors(root);
    mountBoardFilters(root, { enhance: choices.enhanceChoices });
  });
}
