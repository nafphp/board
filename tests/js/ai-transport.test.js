import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, test } from 'node:test';
import { requestOllama } from '../../src/Resources/public/assets/ai/ollama-client.js';
import { localUrl, storageFor } from '../../src/Resources/public/assets/ai/store.js';

const originalFetch = globalThis.fetch;
const encoder = new TextEncoder();

/** A response whose body arrives in three-byte pieces, splitting multi-byte characters. */
function streamed(text) {
  const bytes = encoder.encode(text);

  return new Response(
    new ReadableStream({
      start(controller) {
        for (let i = 0; i < bytes.length; i += 3) controller.enqueue(bytes.slice(i, i + 3));
        controller.close();
      },
    }),
  );
}

const wire = [
  { message: { content: 'Grüße ' } },
  {
    message: {
      content: 'aus Nafinity',
      tool_calls: [{ function: { name: 'nafinity_board', arguments: {} } }],
    },
  },
  { done: true },
]
  .map(JSON.stringify)
  .join('\n');

describe('streaming a reply', () => {
  let sent;

  beforeEach(() => {
    globalThis.fetch = async (url, options) => {
      sent = { url, options };

      return streamed(wire);
    };
  });
  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  test('text arrives whole although the bytes do not', async () => {
    // The pieces cut through "ü" and "ß": decoding each one on its own would
    // produce replacement characters rather than the word.
    let visible = '';
    const result = await requestOllama(
      'http://localhost:11434/',
      '/api/chat',
      { model: 'test' },
      (delta) => (visible += delta),
    );

    assert.equal(visible, 'Grüße aus Nafinity');
    assert.equal(result.message.content, visible);
  });

  test('a tool call survives the stream', async () => {
    const result = await requestOllama(
      'http://localhost:11434/',
      '/api/chat',
      { model: 'test' },
      () => {},
    );

    assert.equal(result.message.tool_calls[0].function.name, 'nafinity_board');
  });

  test('the request carries no credentials and refuses to be redirected', async () => {
    await requestOllama('http://localhost:11434/', '/api/chat', { model: 'test' }, () => {});

    assert.equal(sent.options.credentials, 'omit');
    assert.equal(sent.options.redirect, 'error');
    assert.equal(JSON.parse(sent.options.body).stream, true);
  });
});

describe('errors from the model server', () => {
  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  test('an error inside a 200 response is raised', async () => {
    globalThis.fetch = async () => new Response('{"error":"model unavailable"}\n');

    await assert.rejects(
      requestOllama('http://localhost:11434', '/api/chat', {}, () => {}),
      /model unavailable/,
    );
  });

  test('an error status is raised with what it said', async () => {
    globalThis.fetch = async () => new Response('{"error":"missing model"}', { status: 404 });

    await assert.rejects(requestOllama('http://localhost:11434', '/api/chat', {}), /missing model/);
  });

  test('an aborted request stops rather than waiting', async () => {
    const abort = new AbortController();
    abort.abort();
    globalThis.fetch = async (_, options) => options.signal.throwIfAborted();

    await assert.rejects(
      requestOllama('http://localhost:11434', '/api/chat', {}, null, abort.signal),
      { name: 'AbortError' },
    );
  });
});

describe('which addresses the model may live at', () => {
  // The model server is on the machine in front of the person. Anything else is
  // a way to make their browser fetch something on their behalf.
  for (const [what, value] of [
    ['a public host', 'https://example.com'],
    ['credentials in the address', 'http://user:pass@localhost:11434'],
    ['a path', 'http://localhost:11434/redirect'],
    ['a local file', 'file:///etc/passwd'],
    ['a query that could redirect', 'http://localhost:11434?redirect=example.com'],
  ])
    test(`${what} is refused`, () => {
      assert.throws(() => localUrl(value));
    });

  test('a bare host and port is accepted and given a scheme', () => {
    assert.equal(localUrl('127.0.0.1:11434'), 'http://127.0.0.1:11434');
  });
});

describe('what is kept in the browser', () => {
  test('two people do not share a conversation', () => {
    assert.notEqual(
      storageFor({ dataset: { user: '1', project: '1' } }).messages,
      storageFor({ dataset: { user: '2', project: '1' } }).messages,
    );
  });

  test('two projects of one person do not share a memory', () => {
    assert.notEqual(
      storageFor({ dataset: { user: '1', project: '1' } }).memory,
      storageFor({ dataset: { user: '1', project: '2' } }).memory,
    );
  });
});
