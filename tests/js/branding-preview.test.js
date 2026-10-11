import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  mountBrandingPreview,
  readLogo,
} from '../../src/Resources/public/assets/branding-preview.js';

function setup(readImage) {
  const fields = Object.fromEntries(
    ['name', 'logo', 'remove_logo', 'font', 'color_mode', 'color'].map((name) => [
      name,
      { value: '', checked: false },
    ]),
  );
  Object.assign(fields.name, { value: 'Saved name' });
  Object.assign(fields.font, { value: 'system' });
  Object.assign(fields.color_mode, { value: 'auto' });
  Object.assign(fields.color, { value: '#b2a4ff' });
  fields.logo.setCustomValidity = (value) => (fields.logo.validityMessage = value);
  const attributes = new Map([['src', '/branding/logo/saved.png']]);
  const image = {
    getAttribute: (name) => attributes.get(name),
    setAttribute: (name, value) => attributes.set(name, value),
    removeAttribute: (name) => attributes.delete(name),
  };
  const symbol = {};
  const word = {};
  const styles = {};
  const brand = {
    dataset: {},
    style: { setProperty: (key, value) => (styles[key] = value) },
    querySelector: (selector) =>
      ({ '.brand-image': image, '.brand-symbol': symbol, '.brand-word': word })[selector],
  };
  const error = {};
  const colorField = {};
  const listeners = {};
  const form = {
    elements: { namedItem: (name) => fields[name] },
    querySelector: (selector) =>
      ({
        '.branding-preview .brand': brand,
        '[data-branding-preview-error]': error,
        '[data-branding-color]': colorField,
      })[selector],
    addEventListener: (name, listener) => (listeners[name] = listener),
  };
  mountBrandingPreview(form, { readImage });
  const change = (name) => listeners.change({ target: fields[name] });
  return {
    fields,
    brand,
    word,
    image,
    symbol,
    attributes,
    styles,
    error,
    colorField,
    listeners,
    change,
  };
}

test('name is plain text and typography stays inside the unsaved preview', async () => {
  const state = setup();
  state.fields.name.value = '<img src=x onerror=alert(1)>';
  state.fields.font.value = 'serif';
  state.fields.color_mode.value = 'custom';
  state.listeners.input();
  assert.equal(state.word.textContent, '<img src=x onerror=alert(1)>');
  assert.equal(state.brand.dataset.brandFont, 'serif');
  assert.equal(state.styles['--brand-color'], '#b2a4ff');
  assert.equal(state.colorField.hidden, false);
  state.fields.color_mode.value = 'auto';
  await state.change('color_mode');
  assert.equal(state.styles['--brand-color'], 'inherit');
  assert.equal(state.colorField.hidden, true);
});

test('a slow earlier read cannot replace the newest logo or its validation', async () => {
  const pending = [];
  const state = setup(() => new Promise((resolve, reject) => pending.push({ resolve, reject })));
  state.fields.logo.files = [{}];
  const first = state.change('logo');
  const second = state.change('logo');
  pending[1].resolve('data:image/png;base64,new');
  await second;
  pending[0].reject(new Error('old invalid file'));
  await first;
  assert.equal(state.attributes.get('src'), 'data:image/png;base64,new');
  assert.equal(state.fields.logo.validityMessage, '');
  assert.equal(state.error.hidden, true);
});

test('removing a logo invalidates a pending read and unchecking restores the saved image', async () => {
  let resolve;
  const state = setup(() => new Promise((done) => (resolve = done)));
  state.fields.logo.files = [{}];
  const pending = state.change('logo');
  state.fields.remove_logo.checked = true;
  await state.change('remove_logo');
  resolve('data:image/png;base64,late');
  await pending;
  assert.equal(state.image.hidden, true);
  assert.equal(state.symbol.hidden, false);
  assert.equal(state.attributes.has('src'), false);
  state.fields.remove_logo.checked = false;
  await state.change('remove_logo');
  assert.equal(state.attributes.get('src'), '/branding/logo/saved.png');
  assert.equal(state.image.hidden, false);
});

test('a form reset ignores a pending logo read and clears invalid file state', async () => {
  let resolve;
  const state = setup(() => new Promise((done) => (resolve = done)));
  state.fields.logo.files = [{}];
  const pending = state.change('logo');
  state.listeners.reset();
  resolve('data:image/png;base64,late');
  await pending;
  assert.equal(state.attributes.get('src'), '/branding/logo/saved.png');
  assert.equal(state.fields.logo.validityMessage, '');
});

test('an invalid selection keeps the saved logo and blocks submission until cleared', async () => {
  const state = setup(() => Promise.reject(new Error('invalid')));
  state.fields.logo.files = [{}];
  await state.change('logo');
  assert.equal(state.error.hidden, false);
  assert.notEqual(state.fields.logo.validityMessage, '');
  assert.equal(state.attributes.get('src'), '/branding/logo/saved.png');
  state.fields.logo.files = [];
  await state.change('logo');
  assert.equal(state.error.hidden, true);
  assert.equal(state.fields.logo.validityMessage, '');
});

test('preview rejects unsupported, empty and oversized files before reading them', async () => {
  for (const file of [
    { type: 'image/svg+xml', size: 100 },
    { type: 'image/png', size: 0 },
    { type: 'image/png', size: 524289 },
  ]) {
    await assert.rejects(readLogo(file), /PNG/);
  }
});
