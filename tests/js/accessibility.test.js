import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const css = readFileSync(
  new URL('../../src/Resources/public/assets/app.css', import.meta.url),
  'utf8',
);
const schema = JSON.parse(
  readFileSync(
    new URL('../../src/Resources/public/assets/theme-tokens.json', import.meta.url),
    'utf8',
  ),
);

function minimum(selector, property, density) {
  const body = css.match(
    new RegExp(`(?:^|\\n)${selector.replaceAll('.', '\\.')} \\{([^}]+)\\}`),
  )[1];
  const value = body.match(new RegExp(`${property}:([^;]+);`))?.[1].trim();
  assert.ok(value, `${selector} needs ${property} independent of icon size`);
  const resolved = value.replace(
    /var\(--space-(\d+)\)/g,
    (_, size) => `${Number(size) * density}px`,
  );
  if (/^max\([\d.]+px,\s*[\d.]+px\)$/.test(resolved)) {
    return Math.max(...resolved.match(/[\d.]+/g).map(Number));
  }
  assert.match(resolved, /^[\d.]+px$/);
  return Number.parseFloat(resolved);
}

test('card actions and shared icon controls remain large enough at every supported density', () => {
  for (const density of [
    schema.common.density.min,
    schema.common.density.default,
    schema.common.density.max,
  ]) {
    for (const selector of ['.card-menu', '.icon-button']) {
      for (const dimension of ['min-width', 'min-height']) {
        assert.ok(
          minimum(selector, dimension, density) >= 32,
          `${selector} ${dimension} at density ${density}`,
        );
      }
    }
  }
});

test('starting and stopping a timer keeps its visible and accessible card marker in step', () => {
  const source = readFileSync(
    new URL('../../src/Resources/public/assets/timer.js', import.meta.url),
    'utf8',
  );
  const markers = [];
  const card = {
    querySelector(selector) {
      if (selector === '[data-card-timer]') return markers[0];
      if (selector === '.card-id') return { append: (marker) => markers.push(marker) };
      throw new Error(`Unexpected card selector: ${selector}`);
    },
  };
  const context = {
    CSS: { escape: (value) => value },
    t: () => 'Your timer is running',
    document: {
      querySelector: (selector) => (selector.startsWith('.ticket-card[') ? card : null),
      createElement: () => ({
        dataset: {},
        children: [],
        append(child) {
          this.children.push(child);
        },
        remove() {
          markers.splice(markers.indexOf(this), 1);
        },
      }),
    },
    panel: { dataset: { action: '/projects/1/tickets/NAF-3/timer' } },
  };
  runInNewContext(
    source.slice(source.indexOf('function marker('), source.indexOf('async function send(')) +
      '\nmarker(panel, true); marker(panel, true);',
    context,
  );
  assert.equal(markers.length, 1, 'a server-rendered or existing marker must not be duplicated');
  assert.equal(markers[0].title, 'Your timer is running');
  assert.equal(markers[0].children[0].className, 'sr-only');
  assert.equal(markers[0].children[0].textContent, 'Your timer is running');
  runInNewContext('marker(panel, false);', context);
  assert.equal(markers.length, 0, 'stopping removes the accessible announcement as well');
});
