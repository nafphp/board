// Pointer driven board dragging: a lifted ghost card, a live placeholder slot and
// FLIP motion for every card that has to make room.
import { csrf, toast } from './app.js';
import { celebrate } from './fireworks.js';

const board = document.querySelector('#board');
/*
 * Where this board was read from, kept because the address does not stay put:
 * opening a ticket in the drawer rewrites it to the ticket's, and creating one
 * rewrites it again. Refetching `location.href` after that fetches the ticket.
 * The query string is part of it, so whatever is filtered stays filtered.
 */
const boardUrl = board ? location.href : null;
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
const spring = 'cubic-bezier(.2,1.1,.3,1)';
// Cards making room glide without overshoot: they are rearranged over and over during a drag,
// and a curve that swings past its target never comes to rest.
const glide = 'cubic-bezier(.22,.7,.3,1)';
// How much better another spot has to be before the slot gives up the one it holds. Without
// it a pixel of hand tremor at the boundary between two spots throws the slot back and forth.
const stickiness = 24;
// Pixels per pointer event above which the card counts as travelling rather than aiming.
const travelling = 10;
const holdDelay = 240;
const threshold = 6;
const edgeZone = 72;

const drag = {
  card: null,
  ghost: null,
  slot: null,
  originCell: null,
  originIndex: 0,
  pointer: 0,
  offsetX: 0,
  offsetY: 0,
  width: 0,
  height: 0,
  x: 0,
  y: 0,
  tilt: 0,
  speed: 0,
  hold: 0,
  frame: 0,
  started: false,
};
let suppressClick = false;

function cardsIn(cell) {
  return [...cell.children].filter((node) => node.classList.contains('ticket-card'));
}

function neighbour(node, direction) {
  let sibling = node[direction];
  while (sibling && !sibling.classList.contains('ticket-card')) sibling = sibling[direction];

  return sibling;
}

// Keeps the dashed "nothing here" hint in sync while cards leave and enter a cell.
function emptyHint(cell) {
  const filled = cell.querySelector('.ticket-card,.card-slot');
  const empty = cell.querySelector('.empty-cell');
  if (filled) {
    empty?.remove();
    return;
  }
  if (empty) return;
  const hint = document.createElement('div');
  hint.className = 'empty-cell';
  const mark = document.createElement('span');
  mark.setAttribute('aria-hidden', 'true');
  mark.textContent = '—';
  const text = document.createElement('small');
  text.textContent = board.dataset.emptyHint || '';
  hint.append(mark, text);
  cell.append(hint);
}

// The estimate sum per column is read back off the cards, so a drop needs no round trip
// and the number always describes exactly what is on screen.
function syncPoints() {
  for (const total of document.querySelectorAll('.column-points[data-column-points]')) {
    const cells = board.querySelectorAll(
      `.board-cell[data-column="${total.dataset.columnPoints}"]`,
    );
    const sum = [...cells].reduce(
      (points, cell) =>
        points + cardsIn(cell).reduce((cards, card) => cards + Number(card.dataset.points || 0), 0),
      0,
    );
    const value = total.querySelector('[data-points-value]');
    if (!value || value.textContent.trim() === String(sum)) continue;
    value.textContent = String(sum);
    if (reducedMotion.matches) continue;
    total.animate([{ opacity: 0.35 }, { opacity: 1 }], { duration: 300, easing: glide });
  }
}

function syncCounts() {
  for (const heading of document.querySelectorAll('.column-heading[data-column]')) {
    const cells = board.querySelectorAll(`.board-cell[data-column="${heading.dataset.column}"]`);
    const total = [...cells].reduce((sum, cell) => sum + cardsIn(cell).length, 0);
    const count = heading.querySelector('.count');
    if (!count || count.textContent.trim() === String(total)) continue;
    count.textContent = String(total);
    if (reducedMotion.matches) continue;
    count.animate(
      [{ transform: 'scale(1)' }, { transform: 'scale(1.3)' }, { transform: 'scale(1)' }],
      { duration: 340, easing: spring },
    );
  }
  syncPoints();
  for (const lane of document.querySelectorAll('.swimlane[data-lane]')) {
    const total = [...lane.querySelectorAll('.board-cell')].reduce(
      (sum, cell) => sum + cardsIn(cell).length,
      0,
    );
    const count = lane.querySelector('.lane-count');
    if (count) count.textContent = String(total);
  }
}

// Rects carry transforms, so a card mid-glide reports a position it does not hold.
function layoutBox(node) {
  const transform = getComputedStyle(node).transform;
  const matrix = transform === 'none' ? null : new DOMMatrixReadOnly(transform);
  const box = node.getBoundingClientRect();

  return {
    top: box.top - (matrix?.m42 ?? 0),
    left: box.left - (matrix?.m41 ?? 0),
    height: node.offsetHeight,
  };
}

// The card under the pointer is what the person aims at, not the pointer itself: grabbing a
// card near its edge puts its body far from the cursor.
function anchor() {
  const top = drag.y - drag.offsetY;

  return { x: drag.x - drag.offsetX + drag.width / 2, y: top + drag.height / 2, top };
}

function cellGap(node, x, y) {
  const box = node.getBoundingClientRect();

  return Math.hypot(
    Math.max(box.left - x, 0, x - box.right),
    Math.max(box.top - y, 0, y - box.bottom),
  );
}

// The cell the card is over, zero distance meaning it is inside one. It keeps the cell it
// already occupies until another is clearly closer: between two columns, and below a full
// one, the distances are nearly equal, and a wavering hand would otherwise throw the slot
// from column to column and shuffle both of them on every pixel.
function targetCell(x, y) {
  const current = drag.slot.parentElement?.classList.contains('board-cell')
    ? drag.slot.parentElement
    : null;
  let best = null;
  let distance = Infinity;
  let holding = Infinity;

  for (const node of board.querySelectorAll('.board-cell')) {
    const gap = cellGap(node, x, y);
    if (node === current) holding = gap;
    if (gap >= distance) continue;
    distance = gap;
    best = node;
  }

  return current && distance + stickiness >= holding ? current : best;
}

const flights = new WeakMap();

// First/Last/Invert/Play so neighbouring cards glide instead of jumping.
function flip(nodes, mutate) {
  if (reducedMotion.matches) {
    mutate();
    return;
  }
  const targets = [...new Set(nodes)];
  // Captured while the previous glide still shows, so the next one continues from there.
  const before = new Map(targets.map((node) => [node, node.getBoundingClientRect()]));
  mutate();
  for (const node of targets) {
    const first = before.get(node);
    // Read past any running glide: where the card belongs now, not where it currently shows.
    const last = layoutBox(node);
    const flight = flights.get(node);
    // A card already on its way to exactly this place keeps going. Restarting it would
    // resample a position the compositor may be a frame ahead of, which shows as a twitch.
    if (flight && Math.abs(flight.top - last.top) < 1 && Math.abs(flight.left - last.left) < 1) {
      continue;
    }
    flight?.animation.cancel();
    flights.delete(node);
    const dx = first.left - last.left;
    const dy = first.top - last.top;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) continue;
    flights.set(node, {
      animation: node.animate(
        [{ transform: `translate(${dx}px,${dy}px)` }, { transform: 'none' }],
        { duration: 190, easing: glide },
      ),
      top: last.top,
      left: last.left,
    });
  }
}

function place(cell, before) {
  const source = drag.slot.parentElement;
  flip([...cardsIn(cell), ...(source ? cardsIn(source) : [])], () => {
    cell.insertBefore(drag.slot, before);
    if (source && source !== cell) emptyHint(source);
    emptyHint(cell);
  });
}

// Where the slot sits among the real cards, which is what the origin index is measured against.
function slotIndex() {
  let index = 0;
  let node = drag.slot.previousElementSibling;
  while (node) {
    if (node.classList.contains('ticket-card')) index += 1;
    node = node.previousElementSibling;
  }

  return index;
}

// Where the slot would end up for every possible spot in this cell, measured as if the slot
// were not in the flow. Independent of its current position, so the choice never depends on
// where it happens to sit and cannot settle into a dead zone.
function openings(cell) {
  const gap = parseFloat(getComputedStyle(cell).rowGap) || 0;
  // What the slot takes up right now, which is less than the card while it is still growing.
  const span =
    drag.slot.parentElement === cell ? drag.slot.getBoundingClientRect().height + gap : 0;
  const spots = [];
  let below = false;
  let end = null;

  for (const node of cell.children) {
    if (node === drag.slot) {
      below = true;
      continue;
    }
    if (!node.classList.contains('ticket-card')) continue;
    const box = layoutBox(node);
    const top = box.top - (below ? span : 0);
    spots.push({ before: node, top });
    end = top + box.height + gap;
  }
  if (end !== null) spots.push({ before: null, top: end });

  return spots;
}

// Cards differ in height, so a card held over a shorter one can have its middle below that
// card's middle while it clearly sits higher. The slot therefore goes where the card is:
// the spot whose top comes closest to the top of the card being carried.
function insertionPoint(cell, top) {
  const held =
    drag.slot.parentElement === cell ? neighbour(drag.slot, 'nextElementSibling') : false;
  let best = null;
  let distance = Infinity;
  let holding = Infinity;

  for (const spot of openings(cell)) {
    const gap = Math.abs(spot.top - top);
    if (spot.before === held) holding = gap;
    if (gap >= distance) continue;
    distance = gap;
    best = spot.before;
  }
  if (held !== false && distance + stickiness >= holding) return held;

  return best;
}

function highlight(cell) {
  for (const node of board.querySelectorAll('.board-cell.is-target')) {
    if (node !== cell) node.classList.remove('is-target', 'is-closing');
  }
  if (!cell) return;
  cell.classList.add('is-target');
  cell.classList.toggle('is-closing', cell.dataset.closes === '1');
}

function paintGhost() {
  drag.ghost.style.transform =
    `translate3d(${drag.x - drag.offsetX}px,${drag.y - drag.offsetY}px,0)` +
    ` rotate(${drag.tilt.toFixed(2)}deg) scale(1.035)`;
}

// Marks the column under the card, and settles the slot into it once the hand has stopped
// travelling. Sweeping a card across the board passes over columns on the way; reordering
// each of them would make their cards step aside and straight back again.
function hover() {
  const point = anchor();
  const cell = targetCell(point.x, point.y);
  // Marking a column the slot has not moved into yet would flash each one the card sweeps
  // over, so the outline stays on the column the card would actually land in.
  if (!cell || drag.speed > travelling) return;
  highlight(cell);
  const before = insertionPoint(cell, point.top);
  if (drag.slot.parentElement === cell && drag.slot.nextElementSibling === before) return;
  place(cell, before);
}

function autoScroll() {
  drag.frame = requestAnimationFrame(autoScroll);
  if (!drag.started) return;
  const box = board.getBoundingClientRect();
  if (drag.x < box.left + edgeZone) board.scrollLeft -= (box.left + edgeZone - drag.x) * 0.2;
  else if (drag.x > box.right - edgeZone) board.scrollLeft += (drag.x - box.right + edgeZone) * 0.2;
  if (drag.y < edgeZone) scrollBy(0, -(edgeZone - drag.y) * 0.18);
  else if (drag.y > innerHeight - edgeZone) scrollBy(0, (drag.y - innerHeight + edgeZone) * 0.18);
  // A hand that comes to rest sends no more events, so the slot catches up from here.
  if (drag.speed <= travelling) return;
  drag.speed *= 0.8;
  if (drag.speed <= travelling) hover();
}

function lift(card, x, y) {
  const box = card.getBoundingClientRect();
  drag.started = true;
  drag.card = card;
  drag.offsetX = x - box.left;
  drag.offsetY = y - box.top;
  drag.width = box.width;
  drag.height = box.height;
  drag.x = x;
  drag.y = y;
  drag.tilt = 0;
  drag.speed = 0;
  drag.originCell = card.closest('.board-cell');
  drag.originIndex = cardsIn(drag.originCell).indexOf(card);
  suppressClick = true;

  drag.slot = document.createElement('div');
  drag.slot.className = 'card-slot';
  drag.slot.style.height = box.height + 'px';
  card.before(drag.slot);
  card.remove();

  drag.ghost = card.cloneNode(true);
  drag.ghost.classList.add('ticket-ghost');
  drag.ghost.setAttribute('aria-hidden', 'true');
  drag.ghost.style.width = box.width + 'px';
  document.body.append(drag.ghost);
  paintGhost();
  if (!reducedMotion.matches) {
    drag.ghost.animate(
      [{ transform: drag.ghost.style.transform.replace('scale(1.035)', 'scale(1)') }, {}],
      { duration: 200, easing: spring },
    );
    drag.slot.animate(
      [
        { height: '0px', opacity: 0 },
        { height: box.height + 'px', opacity: 1 },
      ],
      {
        duration: 240,
        easing: spring,
      },
    );
  }
  document.documentElement.classList.add('board-dragging');
  highlight(drag.originCell);
  drag.frame = requestAnimationFrame(autoScroll);
}

async function persist(card, cell) {
  const left = neighbour(drag.slot, 'previousElementSibling');
  const right = neighbour(drag.slot, 'nextElementSibling');
  const body = {
    version: card.dataset.version,
    board_revision: board.dataset.revision,
    column_id: cell.dataset.column,
    swimlane_id: cell.dataset.lane,
    placement: 'between',
    left_id: left?.dataset.ticket ?? null,
    right_id: right?.dataset.ticket ?? null,
  };
  const response = await fetch(
    `/projects/${board.dataset.project}/tickets/${card.dataset.key}/move`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': csrf(),
      },
      body: JSON.stringify(body),
    },
  );

  return [response, await response.json().catch(() => ({}))];
}

function settle(card) {
  card.classList.add('landed');
  setTimeout(() => card.classList.remove('landed'), 640);
}

// Puts a rejected card back where it started and shakes it, so the failure is visible.
function revert(card, cell, index) {
  const current = card.parentElement;
  flip([...cardsIn(cell), ...cardsIn(current)], () => {
    // The card still sits in the list it has to leave, so it never counts as its own anchor.
    const anchor = cardsIn(cell).filter((node) => node !== card)[index] ?? null;
    cell.insertBefore(card, anchor);
    emptyHint(current);
    emptyHint(cell);
  });
  card.classList.add('rejected');
  setTimeout(() => card.classList.remove('rejected'), 520);
  syncCounts();
}

async function commit(cell) {
  const card = drag.card;
  const slot = drag.slot;
  const ghost = drag.ghost;
  const origin = drag.originCell;
  const index = drag.originIndex;
  const closed = card.dataset.status === 'closed';
  const moved = cell !== null && (cell !== origin || slotIndex() !== index);
  const target = slot.getBoundingClientRect();
  const flight = ghost.animate(
    [
      { transform: ghost.style.transform },
      { transform: `translate3d(${target.left}px,${target.top}px,0) rotate(0deg) scale(1)` },
    ],
    { duration: reducedMotion.matches ? 1 : 280, easing: spring, fill: 'forwards' },
  );
  const request = moved ? persist(card, cell) : null;
  if (request) card.dataset.busy = '1';

  // A background tab freezes the animation timeline, so the card must not wait on it.
  await Promise.race([
    flight.finished.catch(() => {}),
    new Promise((done) => setTimeout(done, 400)),
  ]);
  slot.replaceWith(card);
  ghost.remove();
  emptyHint(card.parentElement);
  emptyHint(origin);
  syncCounts();
  settle(card);
  reset();
  if (!request) return;

  try {
    const [response, result] = await request;
    if (!response.ok) {
      toast(result.message || 'Verschieben fehlgeschlagen.');
      document.querySelector('#board-update').hidden = false;
      revert(card, origin, index);
      return;
    }
    card.dataset.version = result.version ?? card.dataset.version;
    card.dataset.status = result.status ?? card.dataset.status;
    board.dataset.revision = result.revision ?? board.dataset.revision;
    if (!closed && card.dataset.status === 'closed') celebrate(card);
  } catch {
    toast('Die Verbindung ist unterbrochen. Bitte lade das Board neu.');
    revert(card, origin, index);
  } finally {
    delete card.dataset.busy;
  }
}

function reset() {
  cancelAnimationFrame(drag.frame);
  clearTimeout(drag.hold);
  document.documentElement.classList.remove('board-dragging');
  highlight(null);
  drag.started = false;
  drag.speed = 0;
  drag.card = null;
  drag.ghost = null;
  drag.slot = null;
  drag.pointer = 0;
}

function cancel() {
  if (!drag.started) {
    reset();
    return;
  }
  place(drag.originCell, cardsIn(drag.originCell)[drag.originIndex] ?? null);
  commit(null);
}

function release() {
  removeEventListener('pointermove', onMove);
  removeEventListener('pointerup', onUp);
  removeEventListener('pointercancel', onCancel);
  clearTimeout(drag.hold);
  if (!drag.started) reset();
}

function onMove(event) {
  if (event.pointerId !== drag.pointer) return;
  const x = event.clientX;
  const y = event.clientY;
  if (!drag.started) {
    if (Math.hypot(x - drag.x, y - drag.y) < threshold) return;
    if (event.pointerType !== 'mouse') {
      release();
      return;
    }
    const card = drag.card;
    clearTimeout(drag.hold);
    lift(card, x, y);
    return;
  }
  event.preventDefault();
  drag.speed = drag.speed * 0.6 + Math.hypot(x - drag.x, y - drag.y) * 0.4;
  drag.tilt = Math.max(-7, Math.min(7, drag.tilt * 0.72 + (x - drag.x) * 0.55));
  drag.x = x;
  drag.y = y;
  paintGhost();
  hover();
}

function onUp(event) {
  if (event.pointerId !== drag.pointer) return;
  release();
  if (!drag.started) return;
  // Letting go is the final aim, however fast the card was moving a moment ago.
  drag.speed = 0;
  hover();
  commit(drag.slot.closest('.board-cell'));
}

function onCancel() {
  release();
  if (drag.started) cancel();
}

function onDown(event) {
  if (drag.started || event.button !== 0) return;
  // A new pointer gesture must never inherit the click suppressed after a previous drag.
  suppressClick = false;
  const card = event.target.closest('.ticket-card[draggable="true"]');
  if (!card || card.dataset.busy || event.target.closest('button')) return;
  drag.pointer = event.pointerId;
  drag.card = card;
  drag.x = event.clientX;
  drag.y = event.clientY;
  addEventListener('pointermove', onMove, { passive: false });
  addEventListener('pointerup', onUp);
  addEventListener('pointercancel', onCancel);
  if (event.pointerType === 'mouse') return;
  // Touch and pen keep scrolling until a deliberate hold picks the card up.
  drag.hold = setTimeout(() => {
    const held = drag.card;
    lift(held, drag.x, drag.y);
    navigator.vibrate?.(12);
  }, holdDelay);
}

if (board) {
  // The sheen follows the pointer, which also hints that a card can be grabbed.
  let sheen = 0;
  board.addEventListener('pointermove', (event) => {
    const card = event.target.closest?.('.ticket-card');
    if (!card || sheen || drag.started) return;
    sheen = requestAnimationFrame(() => {
      sheen = 0;
      const box = card.getBoundingClientRect();
      card.style.setProperty('--mx', ((event.clientX - box.left) / box.width) * 100 + '%');
      card.style.setProperty('--my', ((event.clientY - box.top) / box.height) * 100 + '%');
    });
  });

  if (!reducedMotion.matches) {
    for (const cell of board.querySelectorAll('.board-cell')) {
      const column = [...cell.parentElement.children].indexOf(cell);
      cardsIn(cell).forEach((card, row) => {
        card.style.setProperty('--enter-delay', Math.min(520, column * 45 + row * 55) + 'ms');
        card.dataset.enter = '';
      });
    }
    board.addEventListener('animationend', (event) => {
      if (event.animationName === 'card-enter') delete event.target.dataset.enter;
    });
  }

  // A move through the dialog reloads the page, so the celebration is picked up afterwards.
  const pending = sessionStorage.getItem('nafinity.celebrate');
  if (pending) {
    sessionStorage.removeItem('nafinity.celebrate');
    const card = board.querySelector(`.ticket-card[data-ticket="${CSS.escape(pending)}"]`);
    if (card) setTimeout(() => celebrate(card), 480);
  }
}

/*
 * Bring the board up to date without leaving the page.
 *
 * The children of #board are replaced rather than the element itself: the drag
 * handlers below are bound to that node, and swapping it would take them with
 * it. The counts above the board come from the same response, because a card
 * that appears without the number beside it changing is a board that disagrees
 * with itself.
 *
 * The current address is refetched, query string and all, so whatever is
 * filtered stays filtered -- a new ticket that does not match simply does not
 * show up, which is the truthful answer.
 */
async function refreshBoard(created) {
  if (!board) return;
  const response = await fetch(boardUrl, { headers: { Accept: 'text/html' } }).catch(() => null);
  if (!response?.ok || response.redirected) return;
  const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
  const fresh = parsed.querySelector('#board');
  if (!fresh) return;

  // Only the new card is worth an entrance. Replaying it on every card would
  // shift the whole board for something that happened to one of them.
  for (const card of fresh.querySelectorAll('.ticket-card[data-enter]')) {
    const own = card.querySelector('[data-ticket-link]')?.getAttribute('href');
    if (!created || own !== created) delete card.dataset.enter;
  }

  board.replaceChildren(...fresh.children);
  board.dataset.revision = fresh.dataset.revision ?? board.dataset.revision;
  const meta = document.querySelector('.board-meta');
  const rechnung = parsed.querySelector('.board-meta');
  if (meta && rechnung) meta.replaceWith(rechnung);
  const banner = document.querySelector('#board-update');
  if (banner) banner.hidden = true;
  document.dispatchEvent(new CustomEvent('nafinity:fragment-updated', { detail: { node: board } }));
}
document.addEventListener('nafinity:board-changed', (event) => {
  refreshBoard(event.detail?.url);
});

if (board && board.dataset.filtered === '0') {
  board.addEventListener('pointerdown', onDown);
  // The native drag image would fight the pointer ghost, so it never starts.
  board.addEventListener('dragstart', (event) => event.preventDefault());
  board.addEventListener(
    'click',
    (event) => {
      if (!suppressClick || event.detail === 0) return;
      suppressClick = false;
      event.preventDefault();
      event.stopPropagation();
    },
    true,
  );
  addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !drag.started) return;
    event.preventDefault();
    release();
    cancel();
  });
}
