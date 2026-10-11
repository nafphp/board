// Native GET navigation keeps the board, counts, drag rules and shared URL in
// agreement. A choice applies immediately; only fulltext typing is debounced.
export function reactiveFilters(form) {
  if (!form) return;
  const apply = form.querySelector('[data-apply]');
  if (apply) apply.hidden = true;
  const asked = () => new URLSearchParams(new FormData(form)).toString();
  const loaded = asked();
  let timer;
  let submitting = false;
  let composing = false;

  const submit = () => {
    clearTimeout(timer);
    if (submitting || asked() === loaded) return;
    form.requestSubmit();
  };
  form.addEventListener('submit', () => {
    clearTimeout(timer);
    submitting = true;
  });
  // Choice search inputs have no name and must never apply a board filter.
  form.addEventListener('change', (event) => {
    if (event.target.name && event.target.type !== 'hidden') submit();
  });
  form.addEventListener('input', (event) => {
    if (event.target.name !== 'q' || composing || event.isComposing) return;
    clearTimeout(timer);
    timer = setTimeout(submit, 400);
  });
  form.addEventListener('compositionstart', () => {
    composing = true;
    clearTimeout(timer);
  });
  form.addEventListener('compositionend', (event) => {
    composing = false;
    if (event.target.name === 'q') {
      clearTimeout(timer);
      timer = setTimeout(submit, 400);
    }
  });
}

reactiveFilters(document.querySelector('.filterbar'));

document.addEventListener('click', (event) => {
  const shortcut = event.target.closest?.('[data-profile-section]');
  if (!shortcut) return;
  const dialog = document.getElementById('profile-dialog');
  const section = dialog?.querySelector('[data-profile-section-id="board_filters"]');
  if (section) section.open = true;
});
