/*
 * The dot in the corner: is this page still hearing the server?
 *
 * It reports and nothing else. The socket module decides when it is connected,
 * trying again or finished; this only turns that into something a person can see
 * without opening a console -- because a board that has quietly stopped updating
 * looks exactly like a board where nothing has happened.
 *
 * Every word it shows was translated on the server and handed over in the
 * markup, so nothing here has to know what language this installation speaks.
 */
const strip = document.querySelector('[data-live-status]');

if (strip) {
  const spoken = strip.querySelector('[data-live-text]');

  /** @param {'on'|'off'|'gone'|'waiting'} state */
  function show(state) {
    const said = strip.dataset[`live${state[0].toUpperCase()}${state.slice(1)}`] ?? '';
    strip.dataset.liveStatus = state;
    strip.title = said;
    // The visible part is a coloured dot, which a screen reader cannot see. The
    // sentence is what it reads, and changing the text is what makes it speak.
    if (spoken) spoken.textContent = said;
  }

  document.addEventListener('naf:websocket-open', () => show('on'));
  document.addEventListener('naf:websocket-closed', (event) => {
    // No delay means nothing is waiting: the application said no, and only a
    // fresh page can change that.
    show(event.detail?.retryIn === null ? 'gone' : 'off');
  });
}
