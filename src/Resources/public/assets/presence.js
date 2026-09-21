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

  /** And how many fit in the corner of a card, where the rest are in the title. */
  const ON_A_CARD = 3;

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

  /** One face, however small, drawn the same wherever it is asked for. */
  function face(person, size) {
    const node = document.createElement('span');
    node.className = `avatar ${size}${person.known ? '' : ' unknown'}`;
    node.textContent = person.known ? initial(person.name) : '?';

    return node;
  }

  /** @return {{name: string, known: boolean, place: string|null}} */
  function personOf(subject) {
    return {
      name: people[subject] ?? words.someone,
      known: subject in people,
      place: here.get(subject) ?? null,
    };
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
    const present = [...here.keys()].map(personOf);
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
      stack.append(face(person, 'avatar-small'));
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
      const said = document.createElement('span');
      said.className = 'presence-who';
      const name = document.createElement('strong');
      name.textContent = person.name;
      const place = document.createElement('small');
      place.textContent = spoken(person.place);
      said.append(name, place);
      row.append(face(person, 'avatar-small'), said);
      list.append(row);
    }

    cards();
  }

  /*
   * And on the board itself: the card somebody is reading.
   *
   * The same knowledge the bar draws, put where it is actually useful. Seeing
   * that a colleague is on NAF-6 is worth something in the bar and worth more on
   * NAF-6 -- it is the moment before two people edit the same ticket, which is
   * the one moment a board can still say something about it.
   *
   * A card already carries its key, so nothing had to be added to the template
   * for this: what the socket says a person is at and what the card calls itself
   * are the same string. The marker is drawn outside the card's box and taken
   * out of the flow, so a card does not change size when somebody opens it -- on
   * a board that animates its own rearranging, a card that resizes because
   * somebody elsewhere clicked is a card that appears to move by itself.
   */
  function cards() {
    const watched = new Map();
    for (const [subject, place] of here) {
      if (typeof place === 'string' && place.startsWith('#')) {
        watched.set(place.slice(1), [...(watched.get(place.slice(1)) ?? []), subject]);
      }
    }

    for (const card of document.querySelectorAll('.ticket-card[data-key]')) {
      // Sorted so that the same set of people is the same string however they
      // arrived, and so the faces on a card sit in the order the bar lists them.
      const subjects = (watched.get(card.dataset.key) ?? []).toSorted();
      // Redrawn only when it would come out different. Every presence message
      // asks every card, and a board holds a few hundred of them.
      const signature = subjects.join(',');
      if ((card.dataset.watched ?? '') === signature) continue;

      card.querySelector('.card-watchers')?.remove();
      if (signature === '') {
        delete card.dataset.watched;
        continue;
      }

      const readers = subjects.map(personOf);
      const mark = document.createElement('div');
      mark.className = 'card-watchers';
      // Everybody by name, however many faces there is room for: the corner of
      // a card is not the place to run out of, and the bar beside it is where
      // the full list lives anyway.
      const said = words.watching.replace(
        ':names',
        readers.map((person) => person.name).join(', '),
      );
      mark.title = said;
      mark.setAttribute('role', 'img');
      mark.setAttribute('aria-label', said);
      for (const person of readers.slice(0, ON_A_CARD)) mark.append(face(person, 'tiny'));
      card.prepend(mark);
      card.dataset.watched = signature;
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

  /*
   * A live update replaces the cards it changed, and a replacement card knows
   * nothing of who was reading it. Nothing is recomputed here -- the marks are
   * drawn again from what this page already knows, which is why they survive a
   * board rearranging itself without anybody having to say anything twice.
   */
  document.addEventListener('nafinity:fragment-updated', cards);

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
