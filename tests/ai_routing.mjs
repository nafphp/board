import assert from 'node:assert/strict';
import {
  selectTools,
  EMBEDDING_BATCH_SIZE,
  MAX_TOOL_SCHEMA_CHARS,
} from '../src/Resources/public/assets/ai/tool-router.js';
import { MemoryVectorCache, BrowserVectorCache } from '../src/Resources/public/assets/ai/tool-index.js';
import { ollamaTools } from '../src/Resources/public/assets/ai/tools.js';

const tools = Array.from({ length: 500 }, (_, index) => ({
  name: `tool_${index}`,
  description: index === 321 ? 'semantic_needle' : `Unrelated capability ${index}`,
  inputSchema: { type: 'object', properties: {} },
  meta: { title: `Werkzeug ${index}`, risk: 'read', requires: index === 321 ? ['tool_1'] : [] },
}));
const config = {
  url: 'http://localhost:11434',
  embedding_model: 'embeddinggemma',
  tool_limit: 8,
  embedding_min_score: 0.35,
};
const cache = new MemoryVectorCache();
let digest = 'first-digest';
let inputs = [];
let batches = [];
const request = async (_, path, payload, __, signal) => {
  signal?.throwIfAborted();
  if (path === '/api/tags') return { models: [{ name: 'embeddinggemma:latest', digest }] };
  assert.equal(path, '/api/embed');
  assert.equal(payload.truncate, false);
  batches.push(payload.input.length);
  inputs.push(...payload.input);
  return {
    embeddings: payload.input.map((text) => {
      if (text.startsWith('task: search result'))
        return text.includes('unmatched') ? [0, -1, 0] : [1, 0, 0];
      return text.includes('semantic_needle') ? [1, 0, 0] : [0, 0, 1];
    }),
  };
};
const route = (changes = {}) =>
  selectTools({
    tools,
    question: 'Find the semantic needle',
    config,
    scope: 'alice:project:1',
    cache,
    request,
    ...changes,
  });
let result = await route();
assert.equal(result.mode, 'semantic');
assert.equal(result.embedded, 500);
assert.equal(result.cacheHits, 0);
assert.deepEqual(
  result.tools.map((tool) => tool.name),
  ['tool_321', 'tool_1'],
);
assert.ok(batches.every((size) => size <= EMBEDDING_BATCH_SIZE));
assert.equal(inputs.length, 501);
assert.ok(JSON.stringify(ollamaTools(result.tools)).length <= MAX_TOOL_SCHEMA_CHARS);

inputs = [];
result = await route();
assert.equal(result.cacheHits, 500);
assert.equal(result.embedded, 0);
assert.equal(inputs.length, 1, 'Warm requests embed only the question');

const changed = structuredClone(tools);
changed[321].description += ' with a new argument';
changed[321].inputSchema.properties.version = { type: 'integer' };
result = await route({ tools: changed });
assert.equal(result.embedded, 1, 'Only changed tool definitions are reindexed');
assert.equal(result.cacheHits, 499);
const reordered = structuredClone(tools);
reordered[0].inputSchema = { properties: {}, type: 'object' };
assert.equal(
  (await route({ tools: reordered })).embedded,
  0,
  'Key order does not invalidate the index',
);

result = await route({ tools: tools.filter((tool) => tool.name !== 'tool_321') });
assert.deepEqual(result.tools, [], 'Revoked tools never return from the cached index');
result = await route({ tools: tools.filter((tool) => tool.name !== 'tool_1') });
assert.deepEqual(result.tools, [], 'Actions with an unavailable prerequisite are excluded');
assert.equal((await route({ scope: 'bob:project:1' })).cacheHits, 0);
assert.equal((await route({ scope: 'alice:project:2' })).cacheHits, 0);
digest = 'replacement-model-digest';
assert.equal(
  (await route()).cacheHits,
  0,
  'Replacing a model under the same tag invalidates vectors',
);
assert.deepEqual((await route({ question: 'unmatched topic' })).tools, []);

const commonTools = tools.map((tool) => ({ ...tool, description: 'Shared matching capability' }));
const failure = async () => {
  throw new Error('Ollama unavailable');
};
result = await route({ tools: commonTools, question: 'Shared matching', request: failure });
assert.equal(result.mode, 'keyword');
assert.ok(result.warning);
assert.equal(result.tools.length, 8, 'Failure never sends the entire catalog');
assert.equal(
  (
    await route({
      tools: commonTools,
      question: 'Shared matching',
      config: { ...config, embedding_model: '', tool_limit: 1000 },
    })
  ).tools.length,
  16,
);
assert.deepEqual((await route({ question: 'unmatched topic', request: failure })).tools, []);

result = await route({
  request: async (_, path) =>
    path === '/api/tags'
      ? { models: [{ name: 'embeddinggemma:latest', digest }] }
      : { embeddings: [[NaN, 0]] },
});
assert.equal(result.mode, 'keyword', 'Invalid vectors use a bounded fallback');
const hugeTool = {
  ...tools[321],
  meta: {},
  inputSchema: {
    type: 'object',
    properties: { huge: { type: 'string', description: 'x'.repeat(17000) } },
  },
};
result = await route({ tools: [hugeTool] });
assert.deepEqual(result.tools, [], 'Schema size is bounded independently of tool count');

const aborted = new AbortController();
aborted.abort();
await assert.rejects(route({ signal: aborted.signal }), { name: 'AbortError' });
const browserFallback = new BrowserVectorCache();
await browserFallback.putMany([['derived-key', new Float32Array([1, 0])]]);
assert.deepEqual([...(await browserFallback.getMany(['derived-key'])).get('derived-key')], [1, 0]);
const boundedCache = new MemoryVectorCache();
await boundedCache.putMany(
  Array.from({ length: 2100 }, (_, index) => [String(index), new Float32Array([1, 0])]),
);
assert.equal(boundedCache.entries.size, 2000);
assert.equal((await boundedCache.getMany(['0'])).size, 0);

console.log(
  'AI routing: 500-tool catalog, relevance, prerequisites, batching, warm cache, incremental changes, model replacement, authorization isolation, fallback limits, schema budget, invalid vectors and abort passed.',
);
