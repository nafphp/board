/*
 * Who else is in this project, and what they have open.
 *
 * The server keeps this for us. It already knows which connections hold which
 * channel, so a roster needs no heartbeat from the page, no endpoint to poll
 * and nothing stored anywhere: joining a `presence:` channel is the whole
 * subscription, and closing the tab is the whole unsubscribe.
 *
 * The one thing it cannot know is where somebody is looking, because that fact
 * only ever exists in their own browser. So that half is said rather than
 * observed -- and said as a place rather than a sentence, because the next
 * browser may be reading this application in another language and does its own
 * looking-up. Every word this module shows was translated on the server.
 */
const settings = document.querySelector('script[data-presence-settings]');
const strip = document.querySelector('[data-presence]');

if (settings && strip) {
  const { me, people, at, words } = JSON.parse(settings.textContent || '{}');
  const stack = strip.querySelector('[data-presence-stack]');
  const list = strip.querySelector('[data-presence-list]');

  /** How many faces the bar shows before it starts counting instead. */
  const FACES = 4;

  /** subject -> place, for everybody but me. A place of null means not said yet. */
  const here = new Map();

  /*
   * Where this page is.
   *
   * The page said which of the project's views it is, and a ticket overrides
   * that whenever one is open -- in the drawer or as a page of its own, which
   * is the same question asked of the same markup rather than two rules that
   * could disagree. A ticket is named by its key, which reads the same in every
   * language and is more use than the word "Ticket" would be.
   */
  function where() {
    const ticket = document.querySelector(
      '#ticket-drawer[open] .ticket-workspace, #main-content .ticket-workspace',
    );
    if (!ticket) return at;
    if (ticket.dataset.mode === 'create') return 'new';
    const key = ticket.querySelector('.ticket-key')?.textContent.trim();

    return key ? `#${key}` : 'ticket';
  }

  /*
   * Dropped when there is no connection, which is all the error handling this
   * needs: presence is a courtesy, and the next thing said replaces what was
   * missed.
   */
  function say() {
    document.dispatchEvent(new CustomEvent('naf:websocket-say', { detail: { at: where() } }));
  }

  function initial(name) {
    return (Array.from(name.trim())[0] ?? '?').toUpperCase();
  }

  /**
   * A place as this reader would put it; a ticket key stands for itself.
   *
   * Somebody in the roster who has not said yet is somewhere in the project,
   * which is both true and the only thing that is known about them. It reads
   * better than the blank line the honest answer would leave, and it lasts
   * about as long as one round trip.
   */
  function spoken(place) {
    if (!place) return words.elsewhere;

    return place.startsWith('#') ? place.slice(1) : (words[place] ?? words.elsewhere);
  }

  function draw() {
    const present = [...here.entries()].map(([subject, place]) => ({
      name: people[subject] ?? words.someone,
      known: subject in people,
      place,
    }));
    // People this board knows by name first, and among them by name. Somebody
    // with no name to sort by would otherwise take the front of a list of
    // colleagues, on the strength of what they are called instead of nothing.
    present.sort(
      (one, other) => Number(other.known) - Number(one.known) || one.name.localeCompare(other.name),
    );

    strip.hidden = present.length === 0;
    stack.replaceChildren();
    list.replaceChildren();

    for (const person of present.slice(0, FACES)) {
      const face = document.createElement('span');
      face.className = person.known ? 'avatar avatar-small' : 'avatar avatar-small unknown';
      face.textContent = person.known ? initial(person.name) : '?';
      stack.append(face);
    }
    if (present.length > FACES) {
      const rest = document.createElement('span');
      rest.className = 'avatar avatar-small presence-rest';
      rest.textContent = `+${present.length - FACES}`;
      stack.append(rest);
    }
    stack.setAttribute(
      'aria-label',
      present.map((person) => `${person.name} ${spoken(person.place)}`.trim()).join(', '),
    );

    for (const person of present) {
      const row = document.createElement('li');
      const face = document.createElement('span');
      face.className = person.known ? 'avatar avatar-small' : 'avatar avatar-small unknown';
      face.textContent = person.known ? initial(person.name) : '?';
      const said = document.createElement('span');
      said.className = 'presence-who';
      const name = document.createElement('strong');
      name.textContent = person.name;
      const place = document.createElement('small');
      place.textContent = spoken(person.place);
      said.append(name, place);
      row.append(face, said);
      list.append(row);
    }
  }

  document.addEventListener('naf:websocket-message', (event) => {
    const message = event.detail;
    // My own arrival is not news to me, and my own face in a bar of other
    // people's is the one thing everybody already knows.
    const subject = message?.subject;

    switch (message?.type) {
      case 'presence.here':
        here.clear();
        for (const one of message.present ?? []) if (one !== me) here.set(one, null);
        // Nobody stores where anybody is, so a roster arrives without places.
        // Saying where I am is what gets the others to say it back.
        say();
        break;
      case 'presence.joined':
        if (subject === me) return;
        if (!here.has(subject)) here.set(subject, null);
        // Somebody who just arrived was told who is here and not what they are
        // doing. This is the answer to that, and it goes to the whole channel
        // rather than to them -- a message the others already have costs them a
        // redraw of what they are already looking at.
        say();
        break;
      case 'presence.left':
        if (subject === me) return;
        here.delete(subject);
        break;
      case 'presence.at':
        if (subject === me) return;
        here.set(subject, message.at);
        break;
      default:
        return;
    }

    draw();
  });

  /*
   * A dropped connection means this page no longer knows who is here -- not
   * that nobody is. Showing the last thing it knew would be a bar of people who
   * may have left ten minutes ago, so it shows nothing, and the roster comes
   * back with the connection.
   */
  document.addEventListener('naf:websocket-closed', () => {
    here.clear();
    draw();
  });

  // Opening and closing a ticket is the whole of what moves within a page.
  document.addEventListener('nafinity:ticket-opened', say);
  document.addEventListener('nafinity:drawer-closed', say);

  stack.addEventListener('click', () => {
    const open = strip.hasAttribute('data-open');
    strip.toggleAttribute('data-open', !open);
    stack.setAttribute('aria-expanded', String(!open));
  });
  document.addEventListener('click', (event) => {
    if (strip.contains(event.target)) return;
    strip.removeAttribute('data-open');
    stack.setAttribute('aria-expanded', 'false');
  });
}
