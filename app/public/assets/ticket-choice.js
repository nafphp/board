// Shared ticket picker. Native controls remain the source of form values and the fallback.
const choices = new WeakMap();
let activeChoice;

function renderValue(state) {
  const selected = state.items.filter((item) => item.selected());
  const value = state.root.querySelector('[data-choice-value]');
  value.replaceChildren();
  for (const item of selected.filter((item) => item.value !== '').slice(0, 1)) {
    if (state.multiple) {
      const avatar = document.createElement('span');
      avatar.className = 'avatar avatar-small';
      avatar.textContent = [...item.label][0];
      avatar.setAttribute('aria-hidden', 'true');
      value.append(avatar);
    }
    const label = document.createElement('span');
    label.textContent = item.label;
    value.append(label);
  }
  if (!value.childNodes.length) value.textContent = state.root.dataset.choiceEmpty;
  if (selected.length > 1) {
    const count = document.createElement('span');
    count.className = 'choice-count';
    count.textContent = `+${selected.length - 1}`;
    value.append(count);
  }
  const names = selected.map((item) => item.label).join(', ');
  state.trigger.setAttribute('aria-label', `${state.root.dataset.choiceLabel}: ${names}`);
  state.trigger.title = names;
  for (const item of state.items)
    item.option.setAttribute('aria-selected', String(item.selected()));
}
function highlight(state, item) {
  state.items.forEach((entry) => entry.option.classList.toggle('is-active', entry === item));
  if (item) {
    state.search.setAttribute('aria-activedescendant', item.option.id);
    item.option.scrollIntoView({ block: 'nearest' });
  } else state.search.removeAttribute('aria-activedescendant');
}
function filter(state) {
  const query = state.search.value.trim().toLocaleLowerCase();
  state.items.forEach((item) => {
    item.option.hidden = !item.label.toLocaleLowerCase().includes(query);
  });
  const visible = state.items.filter((item) => !item.option.hidden);
  state.root.querySelector('.choice-empty').hidden = visible.length > 0;
  highlight(state, visible.find((item) => item.selected()) || visible[0]);
}
function position(state) {
  const rect = state.trigger.getBoundingClientRect();
  const viewport = window.visualViewport;
  const top = viewport?.offsetTop || 0;
  const left = viewport?.offsetLeft || 0;
  const width = viewport?.width || innerWidth;
  const bottom = top + (viewport?.height || innerHeight);
  const popupWidth = Math.min(Math.max(rect.width, 260), width - 24);
  const below = bottom - rect.bottom - 16;
  const above = rect.top - top - 16;
  const upwards = below < 220 && above > below;
  state.popup.style.width = `${popupWidth}px`;
  state.popup.style.maxHeight = `${Math.max(100, Math.min(330, upwards ? above : below))}px`;
  state.popup.style.left = `${Math.max(left + 12, Math.min(rect.left, left + width - popupWidth - 12))}px`;
  state.popup.style.top = `${upwards ? Math.max(top + 12, rect.top - state.popup.offsetHeight - 6) : rect.bottom + 6}px`;
}
function close(state, focus = false, dismiss = false) {
  if (state.popup.hidden) return;
  if (state.popup.hidePopover && state.popup.isConnected && state.popup.matches(':popover-open'))
    state.popup.hidePopover();
  state.popup.hidden = true;
  state.trigger.setAttribute('aria-expanded', 'false');
  state.search.setAttribute('aria-expanded', 'false');
  if (activeChoice === state) activeChoice = null;
  if (focus) state.trigger.focus({ preventScroll: true });
  if (dismiss)
    state.root.dispatchEvent(
      new CustomEvent('nafinity:choice-dismiss', { bubbles: true, detail: { focus } }),
    );
}
function choose(state, item) {
  if (state.multiple) {
    if (item.control) item.control.checked = !item.control.checked;
    else
      state.controls.forEach((control) => {
        control.checked = false;
      });
  } else state.select.value = item.value;
  renderValue(state);
  if (!state.multiple) close(state, true);
  const control =
    item.control || state.select || state.controls[0] || state.native.querySelector('input');
  control.dispatchEvent(new Event('input', { bubbles: true }));
  control.dispatchEvent(new Event('change', { bubbles: true }));
}
export function enhanceChoices(scope = document) {
  scope.querySelectorAll('[data-ticket-choice]').forEach((root) => {
    if (choices.has(root)) {
      renderValue(choices.get(root));
      return;
    }
    const state = {
      root,
      native: root.querySelector('[data-choice-native]'),
      trigger: root.querySelector('[data-choice-trigger]'),
      popup: root.querySelector('[data-choice-popup]'),
      search: root.querySelector('[data-choice-search]'),
      list: root.querySelector('.choice-list'),
      select: root.querySelector('select'),
      controls: [...root.querySelectorAll('input[type=checkbox]')],
    };
    state.multiple = !state.select;
    state.items = state.multiple
      ? [
          {
            value: '',
            label: root.dataset.choiceEmpty,
            selected: () => !state.controls.some((input) => input.checked),
          },
          ...state.controls.map((control) => ({
            control,
            value: control.value,
            label: control.closest('label').textContent.trim(),
            selected: () => control.checked,
          })),
        ]
      : [...state.select.options].map((option) => ({
          value: option.value,
          label: option.text,
          selected: () => option.selected,
        }));
    state.items.forEach((item, index) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'choice-option';
      option.tabIndex = -1;
      option.id = `${state.list.id}-${index}`;
      option.setAttribute('role', 'option');
      if (state.multiple && item.value) {
        const avatar = document.createElement('span');
        avatar.className = 'avatar avatar-small';
        avatar.textContent = [...item.label][0];
        avatar.setAttribute('aria-hidden', 'true');
        option.append(avatar);
      }
      const label = document.createElement('span');
      label.textContent = item.label;
      option.append(label);
      const check = document.createElement('span');
      check.className = 'choice-check';
      check.textContent = '✓';
      check.setAttribute('aria-hidden', 'true');
      option.append(check);
      option.addEventListener('mousedown', (event) => event.preventDefault());
      option.addEventListener('click', () => choose(state, item));
      item.option = option;
      state.list.append(option);
    });
    state.search.addEventListener('input', (event) => {
      event.stopPropagation();
      filter(state);
      position(state);
    });
    root.addEventListener('keydown', (event) => {
      if (event.isComposing) return;
      if (state.popup.hidden) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          openChoice(root);
        }
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(state, true, true);
      } else if (['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
        event.preventDefault();
        event.stopPropagation();
        const visible = state.items.filter((item) => !item.option.hidden);
        const index = visible.findIndex((item) => item.option.classList.contains('is-active'));
        if (event.key === 'Enter') {
          if (visible[index]) choose(state, visible[index]);
        } else if (visible.length)
          highlight(
            state,
            visible[
              (index + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length
            ],
          );
      }
    });
    root.addEventListener('focusout', () =>
      setTimeout(() => {
        if (!root.contains(document.activeElement)) close(state, false, true);
      }, 0),
    );
    state.trigger.addEventListener('click', () =>
      state.popup.hidden ? openChoice(root) : close(state, true, true),
    );
    choices.set(root, state);
    state.native.hidden = true;
    state.trigger.hidden = false;
    renderValue(state);
  });
}
export function openChoice(root) {
  enhanceChoices(root.parentElement);
  const state = choices.get(root);
  if (activeChoice && activeChoice !== state) close(activeChoice, false, true);
  activeChoice = state;
  state.search.value = '';
  state.popup.hidden = false;
  if (state.popup.showPopover) state.popup.showPopover();
  state.trigger.setAttribute('aria-expanded', 'true');
  state.search.setAttribute('aria-expanded', 'true');
  position(state);
  filter(state);
  position(state);
  state.search.focus({ preventScroll: true });
}
export function closeChoices(scope) {
  scope.querySelectorAll('[data-ticket-choice]').forEach((root) => {
    const state = choices.get(root);
    if (state) close(state);
  });
}
document.addEventListener('click', (event) => {
  if (activeChoice && !activeChoice.root.contains(event.target)) close(activeChoice, false, true);
});
function reposition(event) {
  if (!activeChoice) return;
  if (!activeChoice.root.isConnected) {
    activeChoice = null;
    return;
  }
  if (!(event.target instanceof Node) || !activeChoice.popup.contains(event.target))
    position(activeChoice);
}
window.addEventListener('resize', reposition);
window.addEventListener('scroll', reposition, true);
window.visualViewport?.addEventListener('resize', reposition);
