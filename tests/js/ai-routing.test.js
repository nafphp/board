import assert from 'node:assert/strict';
import { beforeEach, describe, test } from 'node:test';
import {
  selectTools,
  EMBEDDING_BATCH_SIZE,
  MAX_TOOL_SCHEMA_CHARS,
} from '../../src/Resources/public/assets/ai/tool-router.js';
import {
  MemoryVectorCache,
  BrowserVectorCache,
} from '../../src/Resources/public/assets/ai/tool-index.js';
import { ollamaTools } from '../../src/Resources/public/assets/ai/tools.js';

/**
 * A catalog far larger than any model may be shown at once. One tool in it
 * matches the question; everything else is noise, and tool_321 needs tool_1.
 */
const CATALOG = Array.from({ length: 500 }, (_, index) => ({
  name: `tool_${index}`,
  description: index === 321 ? 'semantic_needle' : `Unrelated capability ${index}`,
  inputSchema: { type: 'object', properties: {} },
  meta: { title: `Werkzeug ${index}`, risk: 'read', requires: index === 321 ? ['tool_1'] : [] },
}));

const CONFIG = {
  url: 'http://localhost:11434',
  embedding_model: 'embeddinggemma',
  tool_limit: 8,
  embedding_min_score: 0.35,
};

/** A stand-in for the model server, recording what it was asked to embed. */
function router({ digest = 'first-digest' } = {}) {
  const state = { inputs: [], batches: [], digest, cache: new MemoryVectorCache() };

  state.request = async (_, path, payload, __, signal) => {
    signal?.throwIfAborted();
    if (path === '/api/tags')
      return { models: [{ name: 'embeddinggemma:latest', digest: state.digest }] };

    assert.equal(path, '/api/embed');
    assert.equal(payload.truncate, false, 'text was cut instead of being embedded whole');
    state.batches.push(payload.input.length);
    state.inputs.push(...payload.input);

    return {
      embeddings: payload.input.map((text) => {
        if (text.startsWith('task: search result'))
          return text.includes('unmatched') ? [0, -1, 0] : [1, 0, 0];

        return text.includes('semantic_needle') ? [1, 0, 0] : [0, 0, 1];
      }),
    };
  };

  state.route = (changes = {}) =>
    selectTools({
      tools: CATALOG,
      question: 'Find the semantic needle',
      config: CONFIG,
      scope: 'alice:project:1',
      cache: state.cache,
      request: state.request,
      ...changes,
    });

  return state;
}

describe('a cold index', () => {
  let state;
  beforeEach(() => {
    state = router();
  });

  test('the matching tool is found, with what it needs to run', async () => {
    const result = await state.route();

    assert.equal(result.mode, 'semantic');
    assert.deepEqual(
      result.tools.map((tool) => tool.name),
      ['tool_321', 'tool_1'],
    );
  });

  test('the whole catalog is embedded, in batches', async () => {
    const result = await state.route();

    assert.equal(result.embedded, 500);
    assert.equal(result.cacheHits, 0);
    assert.ok(
      state.batches.every((size) => size <= EMBEDDING_BATCH_SIZE),
      'a batch was larger than the model accepts',
    );
    assert.equal(state.inputs.length, 501, 'the question is embedded alongside the catalog');
  });

  test('what is handed to the model fits its budget', async () => {
    const result = await state.route();

    assert.ok(JSON.stringify(ollamaTools(result.tools)).length <= MAX_TOOL_SCHEMA_CHARS);
  });
});

describe('an index that is already warm', () => {
  let state;
  beforeEach(async () => {
    state = router();
    await state.route();
    state.inputs = [];
  });

  test('nothing is embedded a second time', async () => {
    const result = await state.route();

    assert.equal(result.cacheHits, 500);
    assert.equal(result.embedded, 0);
    assert.equal(state.inputs.length, 1, 'a warm request embeds only the question');
  });

  test('only a definition that really changed is indexed again', async () => {
    const changed = structuredClone(CATALOG);
    changed[321].description += ' with a new argument';
    changed[321].inputSchema.properties.version = { type: 'integer' };

    const result = await state.route({ tools: changed });

    assert.equal(result.embedded, 1);
    assert.equal(result.cacheHits, 499);
  });

  test('writing the same schema in a different order changes nothing', async () => {
    const reordered = structuredClone(CATALOG);
    reordered[0].inputSchema = { properties: {}, type: 'object' };

    assert.equal((await state.route({ tools: reordered })).embedded, 0);
  });

  /**
   * The index is keyed by the model that built it. A tag like "latest" can point
   * at different weights over time, and vectors from the old ones are not
   * comparable with the new.
   */
  test('replacing the model behind the tag invalidates the vectors', async () => {
    state.digest = 'replacement-model-digest';

    assert.equal((await state.route()).cacheHits, 0);
  });
});

describe('the index belongs to one person in one project', () => {
  let state;
  beforeEach(async () => {
    state = router();
    await state.route();
  });

  test('another person gets no hits from it', async () => {
    assert.equal((await state.route({ scope: 'bob:project:1' })).cacheHits, 0);
  });

  test('another project gets no hits from it', async () => {
    assert.equal((await state.route({ scope: 'alice:project:2' })).cacheHits, 0);
  });

  test('a tool that is gone never comes back out of the cache', async () => {
    const result = await state.route({ tools: CATALOG.filter((tool) => tool.name !== 'tool_321') });

    assert.deepEqual(result.tools, []);
  });

  test('a tool whose prerequisite is unavailable is left out', async () => {
    const result = await state.route({ tools: CATALOG.filter((tool) => tool.name !== 'tool_1') });

    assert.deepEqual(result.tools, []);
  });

  test('a question nothing answers returns nothing', async () => {
    assert.deepEqual((await state.route({ question: 'unmatched topic' })).tools, []);
  });
});

describe('when the model server cannot be reached', () => {
  const unavailable = async () => {
    throw new Error('Ollama unavailable');
  };
  // Everything matches equally, so only the limit decides how many come back.
  const common = CATALOG.map((tool) => ({ ...tool, description: 'Shared matching capability' }));
  let state;
  beforeEach(() => {
    state = router();
  });

  test('it falls back to keywords and says so', async () => {
    const result = await state.route({
      tools: common,
      question: 'Shared matching',
      request: unavailable,
    });

    assert.equal(result.mode, 'keyword');
    assert.ok(result.warning, 'the fallback was silent');
  });

  test('the fallback never sends the entire catalog', async () => {
    const result = await state.route({
      tools: common,
      question: 'Shared matching',
      request: unavailable,
    });

    assert.equal(result.tools.length, 8);
  });

  /** Even asked for a thousand, the keyword path has a ceiling of its own. */
  test('a limit larger than the ceiling does not raise it', async () => {
    const result = await state.route({
      tools: common,
      question: 'Shared matching',
      config: { ...CONFIG, embedding_model: '', tool_limit: 1000 },
    });

    assert.equal(result.tools.length, 16);
  });

  test('a question nothing answers still returns nothing', async () => {
    assert.deepEqual(
      (await state.route({ question: 'unmatched topic', request: unavailable })).tools,
      [],
    );
  });

  test('vectors that are not numbers fall back rather than ranking on nonsense', async () => {
    const result = await state.route({
      request: async (_, path) =>
        path === '/api/tags'
          ? { models: [{ name: 'embeddinggemma:latest', digest: 'first-digest' }] }
          : { embeddings: [[NaN, 0]] },
    });

    assert.equal(result.mode, 'keyword');
  });
});

describe('bounds', () => {
  test('one tool with an enormous schema is still too much', async () => {
    const huge = {
      ...CATALOG[321],
      meta: {},
      inputSchema: {
        type: 'object',
        properties: { huge: { type: 'string', description: 'x'.repeat(17000) } },
      },
    };

    assert.deepEqual((await router().route({ tools: [huge] })).tools, []);
  });

  test('an aborted selection stops', async () => {
    const aborted = new AbortController();
    aborted.abort();

    await assert.rejects(router().route({ signal: aborted.signal }), { name: 'AbortError' });
  });
});

describe('the caches themselves', () => {
  test('the browser cache returns what was put in it', async () => {
    const cache = new BrowserVectorCache();
    await cache.putMany([['derived-key', new Float32Array([1, 0])]]);

    assert.deepEqual([...(await cache.getMany(['derived-key'])).get('derived-key')], [1, 0]);
  });

  /** It holds a bounded number of vectors, and drops the oldest to stay inside it. */
  test('the memory cache does not grow without limit', async () => {
    const cache = new MemoryVectorCache();
    await cache.putMany(
      Array.from({ length: 2100 }, (_, index) => [String(index), new Float32Array([1, 0])]),
    );

    assert.equal(cache.entries.size, 2000);
    assert.equal((await cache.getMany(['0'])).size, 0, 'the oldest entry was kept');
  });
});
