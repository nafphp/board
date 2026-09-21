/*
 * Whether the filter row still describes the board underneath it.
 *
 * Changing a filter changes nothing until it is applied, so between the two
 * there is a board showing one thing and a row saying another -- and nothing on
 * screen admits it. The button does now: it brightens as soon as the row differs
 * from what this page was loaded with, and goes quiet again the moment it
 * matches, which it does if you change a filter back.
 *
 * Compared against what the page was loaded with rather than against "empty",
 * because a board opened on a filtered link is already showing a filtered board,
 * and there is nothing to apply about that.
 */
const form = document.querySelector('.filterbar');
const apply = form?.querySelector('[data-apply]');

if (form && apply) {
  // Every control the form would actually submit, in the order it would submit
  // them -- so this asks the same question the address bar would answer.
  const asked = () => new URLSearchParams(new FormData(form)).toString();
  const loaded = asked();

  const check = () => apply.toggleAttribute('data-changed', asked() !== loaded);

  // Typed into, picked from a list, or set by the choice widget, which says both.
  form.addEventListener('input', check);
  form.addEventListener('change', check);
}
