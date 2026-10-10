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
