import assert from 'node:assert/strict';
import { requestOllama } from '../src/Resources/public/assets/ai/ollama-client.js';
import { localUrl, storageFor } from '../src/Resources/public/assets/ai/store.js';

let sent;
const originalFetch = globalThis.fetch;
const encoder = new TextEncoder();
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
const bytes = encoder.encode(wire);
globalThis.fetch = async (url, options) => {
  sent = { url, options };
  return new Response(
    new ReadableStream({
      start(controller) {
        for (let i = 0; i < bytes.length; i += 3) controller.enqueue(bytes.slice(i, i + 3));
        controller.close();
      },
    }),
  );
};
let visible = '';
const result = await requestOllama(
  'http://localhost:11434/',
  '/api/chat',
  { model: 'test' },
  (delta) => (visible += delta),
);
assert.equal(visible, 'Grüße aus Nafinity');
assert.equal(result.message.content, visible);
assert.equal(result.message.tool_calls[0].function.name, 'nafinity_board');
assert.equal(sent.options.credentials, 'omit');
assert.equal(sent.options.redirect, 'error');
assert.equal(JSON.parse(sent.options.body).stream, true);
globalThis.fetch = async () => new Response('{"error":"model unavailable"}\n');
await assert.rejects(
  requestOllama('http://localhost:11434', '/api/chat', {}, () => {}),
  /model unavailable/,
);
globalThis.fetch = async () => new Response('{"error":"missing model"}', { status: 404 });
await assert.rejects(requestOllama('http://localhost:11434', '/api/chat', {}), /missing model/);
const abort = new AbortController();
abort.abort();
globalThis.fetch = async (_, options) => {
  options.signal.throwIfAborted();
};
await assert.rejects(requestOllama('http://localhost:11434', '/api/chat', {}, null, abort.signal), {
  name: 'AbortError',
});
for (const value of [
  'https://example.com',
  'http://user:pass@localhost:11434',
  'http://localhost:11434/redirect',
  'file:///etc/passwd',
  'http://localhost:11434?redirect=example.com',
])
  assert.throws(() => localUrl(value));
assert.equal(localUrl('127.0.0.1:11434'), 'http://127.0.0.1:11434');
assert.notEqual(
  storageFor({ dataset: { user: '1', project: '1' } }).messages,
  storageFor({ dataset: { user: '2', project: '1' } }).messages,
);
assert.notEqual(
  storageFor({ dataset: { user: '1', project: '1' } }).memory,
  storageFor({ dataset: { user: '1', project: '2' } }).memory,
);
globalThis.fetch = originalFetch;
console.log(
  'AI transport: chunk boundaries, Unicode, tool calls, API errors, aborts, local URLs and storage isolation passed.',
);
