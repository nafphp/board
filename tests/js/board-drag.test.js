import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = await readFile(
  new URL('../../src/Resources/public/assets/board.js', import.meta.url),
  'utf8',
);
// Exercise the real lift function with DOM effects recorded, without booting the page.
const liftSource = source.slice(
  source.indexOf('function lift('),
  source.indexOf('async function persist('),
);

for (const height of [80, 240]) {
  for (const reduced of [false, true]) {
    test(`lifting a ${height}px card reserves its layout size (reduced motion: ${reduced})`, () => {
      const animations = [];
      const cell = {};
      const node = () => ({
        style: {},
        classList: { add() {} },
        setAttribute() {},
        animate(frames) {
          animations.push(frames);
        },
      });
      const ghost = node();
      let slot;
      const card = {
        offsetWidth: 300,
        offsetHeight: height,
        // The :active transform scales the visual box while the layout stays full-sized.
        getBoundingClientRect: () => ({ left: 10, top: 20, width: 295.5, height: height * 0.985 }),
        closest: () => cell,
        cloneNode: () => ghost,
        before(value) {
          slot = value;
        },
        remove() {
          assert.equal(slot.style.height, `${height}px`, 'space must be reserved before removal');
        },
      };
      const drag = {};
      runInNewContext(liftSource + '\nlift(card, 40, 50);', {
        card,
        drag,
        document: {
          createElement: node,
          body: { append() {} },
          documentElement: { classList: { add() {} } },
        },
        cardsIn: () => [card],
        reducedMotion: { matches: reduced },
        spring: 'ease',
        paintGhost() {
          drag.ghost.style.transform = 'scale(1.035)';
        },
        highlight() {},
        requestAnimationFrame() {},
        autoScroll() {},
      });
      assert.equal(slot.style.height, `${height}px`);
      assert.equal(ghost.style.width, '300px');
      assert.equal(drag.height, height);
      assert.equal(drag.width, 300);
      assert.equal(animations.length, reduced ? 0 : 2);
      for (const frames of animations) {
        for (const frame of frames) {
          assert.equal('height' in frame, false, 'animation must not resize the reserved gap');
          assert.equal('width' in frame, false);
        }
      }
    });
  }
}

// A small DOM fixture for the real module's drag lifecycle. It records structure and
// layout, so the tests can check empty hints, retained gaps, placements and cleanup together.
function boardFixture({ failure = false } = {}) {
  function element(className = '', height = 0) {
    const node = {
      className,
      children: [],
      parentElement: null,
      dataset: {},
      style: {},
      addEventListener() {},
      setAttribute() {},
      get offsetWidth() {
        return 300;
      },
      get offsetHeight() {
        return parseFloat(this.style.height) || height;
      },
      get previousElementSibling() {
        return this.parentElement?.children[this.parentElement.children.indexOf(this) - 1] ?? null;
      },
      get nextElementSibling() {
        return this.parentElement?.children[this.parentElement.children.indexOf(this) + 1] ?? null;
      },
      get isConnected() {
        return this.parentElement !== null;
      },
      getBoundingClientRect() {
        const preceding =
          this.parentElement?.children.slice(0, this.parentElement.children.indexOf(this)) ?? [];
        const top = 100 + preceding.reduce((sum, sibling) => sum + sibling.offsetHeight + 12, 0);
        return {
          left: 10,
          right: 310,
          top,
          bottom: top + this.offsetHeight,
          width: 300,
          height: this.offsetHeight,
        };
      },
      remove() {
        if (!this.parentElement) return;
        const siblings = this.parentElement.children;
        siblings.splice(siblings.indexOf(this), 1);
        this.parentElement = null;
      },
      insertBefore(child, before) {
        child.remove();
        const index = before === null ? this.children.length : this.children.indexOf(before);
        assert.ok(index >= 0, 'insertion anchor must belong to its cell');
        this.children.splice(index, 0, child);
        child.parentElement = this;
      },
      append(...children) {
        for (const child of children) this.insertBefore(child, null);
      },
      before(child) {
        this.parentElement.insertBefore(child, this);
      },
      replaceWith(child) {
        this.before(child);
        this.remove();
      },
      cloneNode() {
        const clone = element(this.className, height);
        clone.style = { ...this.style };
        clone.dataset = { ...this.dataset };
        return clone;
      },
      closest(selector) {
        return this.matches(selector) ? this : (this.parentElement?.closest(selector) ?? null);
      },
      matches(selector) {
        return selector.split(',').some((part) =>
          part
            .trim()
            .split('.')
            .slice(1)
            .every((name) => this.classList.contains(name)),
        );
      },
      querySelectorAll(selector) {
        return this.children.flatMap((child) => [
          ...(child.matches(selector) ? [child] : []),
          ...child.querySelectorAll(selector),
        ]);
      },
      querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
      },
      animate() {
        return { finished: Promise.resolve(), cancel() {} };
      },
    };
    node.classList = {
      contains: (name) => node.className.split(' ').includes(name),
      add: (...names) => {
        node.className = [...new Set([...node.className.split(' '), ...names])].join(' ');
      },
      remove: (...names) => {
        node.className = node.className
          .split(' ')
          .filter((name) => !names.includes(name))
          .join(' ');
      },
      toggle(name, force) {
        if (force ?? !this.contains(name)) this.add(name);
        else this.remove(name);
      },
    };
    return node;
  }
  const board = element('board');
  board.dataset = { filtered: '0', revision: '1', project: '1', emptyHint: 'No tickets' };
  const origin = element('board-cell');
  const target = element('board-cell');
  origin.dataset = { column: '1', lane: '1' };
  target.dataset = { column: '2', lane: '1' };
  board.append(origin, target);
  const card = element('ticket-card', 80);
  card.dataset = { ticket: '2', key: 'TEST-2', version: '1' };
  origin.append(card);
  target.append(element('empty-cell', 135));
  const requests = [];
  const context = {
    document: {
      querySelector: (selector) => (selector === '#board' ? board : { hidden: true }),
      querySelectorAll: () => [],
      createElement: () => element(),
      addEventListener() {},
      body: element('body'),
      documentElement: element('html'),
    },
    location: { href: 'http://localhost/projects/1' },
    URL,
    matchMedia: () => ({ matches: true }),
    sessionStorage: { getItem: () => null },
    getComputedStyle: () => ({ transform: 'none', rowGap: '12px' }),
    addEventListener() {},
    removeEventListener() {},
    requestAnimationFrame() {},
    cancelAnimationFrame() {},
    setTimeout() {},
    clearTimeout() {},
    fetch: async (_url, request) => {
      requests.push(JSON.parse(request.body));
      return { ok: !failure, json: async () => ({ version: '2', status: 'open', revision: '2' }) };
    },
  };
  const isolated =
    source.replace(/^import .*;$/gm, '') +
    '\nthis.lifecycle = { drag, lift, place, openings, slotIndex, land, cancel };';
  runInNewContext(
    'const t = message => message; const csrf = () => ""; const toast = () => {}; const celebrate = () => {};\n' +
      isolated,
    context,
  );
  return { ...context.lifecycle, board, origin, target, card, element, requests };
}

function slots(cell) {
  return cell.querySelectorAll('.card-slot');
}

test('leaving the only card reserves a muted origin instead of creating a large empty hint', () => {
  const fixture = boardFixture();
  const { lift, place, drag, card, origin, target } = fixture;
  lift(card, 40, 130);
  const reserved = drag.originSlot;
  place(target, null);
  assert.equal(reserved.parentElement, origin);
  assert.equal(reserved.style.height, '80px');
  assert.equal(reserved.classList.contains('is-active'), false);
  assert.equal(drag.slot.classList.contains('is-active'), true);
  assert.equal(drag.slot.style.height, '80px');
  assert.equal(origin.querySelector('.empty-cell'), null);
  assert.equal(target.querySelector('.empty-cell'), null);
  assert.equal(slots(origin).length, 1);
  assert.equal(slots(target).length, 1);
});

test('repeated returns activate the same origin without adding another gap', () => {
  const { lift, place, drag, card, origin, target, board } = boardFixture();
  lift(card, 40, 130);
  const reserved = drag.originSlot;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    place(target, null);
    place(origin, null);
    assert.equal(drag.slot, reserved);
    assert.equal(reserved.classList.contains('is-active'), true);
    assert.equal(slots(board).length, 1);
    assert.equal(origin.querySelector('.empty-cell'), null);
    assert.ok(target.querySelector('.empty-cell'));
  }
});

test('the origin keeps neighbouring cards in place while the target is in another column', () => {
  const { lift, place, card, origin, target, element } = boardFixture();
  const below = element('ticket-card', 120);
  origin.append(below);
  const top = below.getBoundingClientRect().top;
  lift(card, 40, 130);
  place(target, null);
  assert.equal(below.getBoundingClientRect().top, top);
});

test('same-column reordering offers the reserved origin once and reuses it on return', () => {
  const { lift, place, openings, drag, card, origin, element, slotIndex } = boardFixture();
  const above = element('ticket-card', 100);
  const below = element('ticket-card', 120);
  origin.insertBefore(above, card);
  origin.append(below);
  const originTop = card.getBoundingClientRect().top;
  lift(card, 40, originTop + 30);
  place(origin, above);
  assert.equal(slots(origin).length, 2);
  assert.equal(slotIndex(), 0);
  const spots = openings(origin);
  assert.deepEqual(
    Array.from(spots, (spot) => spot.before),
    [above, below, null],
  );
  assert.equal(spots.find((spot) => spot.before === below).top, originTop);
  place(origin, below);
  assert.equal(drag.slot, drag.originSlot);
  assert.equal(slotIndex(), 1);
  assert.equal(slots(origin).length, 1);
});

test('an origin at the end is offered at the reserved gap, not below it', () => {
  const { lift, openings, card, origin, element } = boardFixture();
  const above = element('ticket-card', 100);
  origin.insertBefore(above, card);
  const top = card.getBoundingClientRect().top;
  lift(card, 40, top + 30);
  const spots = openings(origin);
  assert.equal(spots.length, 2);
  assert.equal(spots[1].before, null);
  assert.equal(spots[1].top, top);
});

test('dropping clears both placeholders and sends only real ticket neighbours', async () => {
  const { lift, place, land, drag, card, origin, target, board, element, requests } =
    boardFixture();
  const left = element('ticket-card', 100);
  left.dataset.ticket = '3';
  target.append(left);
  lift(card, 40, 130);
  place(target, null);
  await land(target);
  assert.equal(card.parentElement, target);
  assert.equal(slots(board).length, 0);
  assert.equal(drag.originSlot, null);
  assert.ok(origin.querySelector('.empty-cell'));
  assert.equal(requests.length, 1);
  assert.equal(requests[0].left_id, '3');
  assert.equal(requests[0].right_id, null);
});

test('returning and dropping at the origin sends no move and leaves no placeholders', async () => {
  const { lift, place, land, card, origin, target, board, requests } = boardFixture();
  lift(card, 40, 130);
  place(target, null);
  place(origin, null);
  await land(origin);
  assert.equal(card.parentElement, origin);
  assert.equal(slots(board).length, 0);
  assert.equal(origin.querySelector('.empty-cell'), null);
  assert.equal(requests.length, 0);
});

test('cancelling restores the reserved position without saving or leaving a second slot', async () => {
  const { lift, place, cancel, card, origin, target, board, requests } = boardFixture();
  lift(card, 40, 130);
  place(target, null);
  cancel();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(card.parentElement, origin);
  assert.equal(slots(board).length, 0);
  assert.equal(requests.length, 0);
});

test('a rejected drop restores the card and removes both placeholders', async () => {
  const { lift, place, land, card, origin, target, board } = boardFixture({ failure: true });
  lift(card, 40, 130);
  place(target, null);
  await land(target);
  assert.equal(card.parentElement, origin);
  assert.equal(slots(board).length, 0);
  assert.equal(origin.querySelector('.empty-cell'), null);
  assert.ok(target.querySelector('.empty-cell'));
});
