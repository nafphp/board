import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.document = { querySelector: () => null, addEventListener() {} };
const { reactiveFilters } = await import('../../src/Resources/public/assets/filters.js');
delete globalThis.document;

function setup(context) {
  const original = globalThis.FormData;
  globalThis.FormData = class {
    constructor(form) {
      return form.values;
    }
  };
  context.after(() => {
    globalThis.FormData = original;
  });
  context.mock.timers.enable({ apis: ['setTimeout'] });
  const listeners = {};
  const button = { hidden: false };
  const form = {
    values: [
      ['q', ''],
      ['priority', ''],
    ],
    submitted: [],
    querySelector: () => button,
    addEventListener: (name, handler) => {
      listeners[name] = handler;
    },
    requestSubmit() {
      this.submitted.push(new URLSearchParams(this.values).toString());
      listeners.submit();
    },
  };
  reactiveFilters(form);
  return {
    form,
    button,
    emit: (name, target = {}, extra = {}) => listeners[name]({ target, ...extra }),
  };
}

test('choosing an option applies all filter values immediately and only once', (context) => {
  const { form, emit, button } = setup(context);
  form.values = [
    ['q', 'sunflower'],
    ['priority', 'low'],
    ['filters[example.reviewed]', '0'],
  ];
  emit('input', { name: 'priority' });
  emit('change', { name: 'priority', type: 'select-one' });
  emit('change', { name: 'priority', type: 'select-one' });
  assert.equal(button.hidden, true);
  assert.deepEqual(form.submitted, ['q=sunflower&priority=low&filters%5Bexample.reviewed%5D=0']);
});

test('search waits until typing stops', (context) => {
  const { form, emit } = setup(context);
  form.values[0][1] = 'sun';
  emit('input', { name: 'q' });
  context.mock.timers.tick(300);
  form.values[0][1] = 'sunflower';
  emit('input', { name: 'q' });
  context.mock.timers.tick(399);
  assert.equal(form.submitted.length, 0);
  context.mock.timers.tick(1);
  assert.equal(form.submitted.length, 1);
  assert.match(form.submitted[0], /q=sunflower/);
});

test('searching inside a choice never submits the board', (context) => {
  const { form, emit } = setup(context);
  form.values[0][1] = 'draft';
  emit('input', { name: '', type: 'search' });
  emit('change', { name: '', type: 'search' });
  context.mock.timers.tick(1000);
  assert.equal(form.submitted.length, 0);
});

test('returning to the loaded values cancels a pending search', (context) => {
  const { form, emit } = setup(context);
  form.values = [
    ['q', 'draft'],
    ['priority', ''],
  ];
  emit('input', { name: 'q' });
  form.values[0][1] = '';
  emit('input', { name: 'q' });
  context.mock.timers.tick(500);
  assert.equal(form.submitted.length, 0);
});

test('clearing an initially active filter applies the empty value', (context) => {
  const { form, emit } = setup(context);
  // Mount another form on an already filtered board.
  form.values = [
    ['q', ''],
    ['priority', 'low'],
  ];
  reactiveFilters(form);
  form.values[1][1] = '';
  emit('change', { name: 'priority', type: 'select-one' });
  assert.deepEqual(form.submitted, ['q=&priority=']);
});

test('an option selection cancels the pending search submission', (context) => {
  const { form, emit } = setup(context);
  form.values[0][1] = 'draft';
  emit('input', { name: 'q' });
  form.values[1][1] = 'high';
  emit('change', { name: 'priority', type: 'select-one' });
  context.mock.timers.tick(500);
  assert.deepEqual(form.submitted, ['q=draft&priority=high']);
});

test('text composition finishes before the search applies', (context) => {
  const { form, emit } = setup(context);
  form.values[0][1] = 'draft';
  emit('input', { name: 'q' });
  emit('compositionstart', { name: 'q' });
  emit('input', { name: 'q' }, { isComposing: true });
  context.mock.timers.tick(500);
  assert.equal(form.submitted.length, 0);
  emit('compositionend', { name: 'q' });
  context.mock.timers.tick(400);
  assert.equal(form.submitted.length, 1);
});
