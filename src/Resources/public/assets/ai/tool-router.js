import { requestOllama } from './ollama-client.js';
import { localUrl } from './store.js';
import { BrowserVectorCache } from './tool-index.js';
import { ollamaTools } from './tools.js';

export const MAX_TOOL_SCHEMA_CHARS = 16000;
export const EMBEDDING_BATCH_SIZE = 32;
const sharedCache = new BrowserVectorCache();
const stopWords = new Set(
  'the a an in of to for and or is are with this that my me i der die das ein eine einen und oder im in von für mit ist sind mir ich bitte welche was wie es gibt diesem projekt project'.split(
    ' ',
  ),
);

const stable = (value) => {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object')
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, stable(value[key])]),
    );
  return value;
};

async function fingerprint(value) {
  const bytes = new TextEncoder().encode(JSON.stringify(stable(value)));
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function description(tool) {
  const properties = tool.inputSchema?.properties || {};
  const argumentsText = Object.entries(properties)
    .map(
      ([name, schema]) => `${name}: ${schema.description || ''} ${(schema.enum || []).join(' ')}`,
    )
    .join('\n')
    .slice(0, 1500);
  return `${tool.meta?.title || tool.name}\n${tool.name}\n${tool.description || ''}\n${(tool.meta?.keywords || []).join(' ')}\n${argumentsText}`.slice(
    0,
    4000,
  );
}

function embeddingInput(model, text, query) {
  if (model.toLowerCase().includes('embeddinggemma'))
    return query ? `task: search result | query: ${text}` : `title: none | text: ${text}`;
  if (model.toLowerCase().includes('nomic-embed-text'))
    return `${query ? 'search_query' : 'search_document'}: ${text}`;
  return text;
}

function normalized(vector, dimensions = null) {
  if (
    !(Array.isArray(vector) || vector instanceof Float32Array) ||
    !vector.length ||
    vector.length > 4096 ||
    (dimensions !== null && dimensions !== vector.length)
  )
    throw new Error('Ungültige Embedding-Dimensionen.');
  if ([...vector].some((value) => typeof value !== 'number' || !Number.isFinite(value)))
    throw new Error('Ungültige Embedding-Werte.');
  const norm = Math.sqrt(vector.reduce((sum, value) => sum + value * value, 0));
  if (!Number.isFinite(norm) || norm === 0) throw new Error('Leerer Embedding-Vektor.');
  return Float32Array.from(vector, (value) => value / norm);
}

function lexicalScores(tools, question) {
  const tokenize = (text) =>
    new Set(
      (text.toLocaleLowerCase().match(/[\p{L}\p{N}]+/gu) || []).filter(
        (word) => word.length > 2 && !stopWords.has(word),
      ),
    );
  const terms = tokenize(question);
  const documents = tools.map((tool) => tokenize(description(tool)));
  const frequencies = new Map();
  for (const document of documents)
    for (const term of terms)
      if (document.has(term)) frequencies.set(term, (frequencies.get(term) || 0) + 1);
  return tools.map((tool, index) => ({
    tool,
    score: [...terms].reduce(
      (score, term) =>
        score +
        (documents[index].has(term) ? Math.log(1 + tools.length / frequencies.get(term)) : 0),
      0,
    ),
  }));
}

function boundedSelection(ranked, allTools, limit, floor) {
  const catalog = new Map(allTools.map((tool) => [tool.name, tool]));
  const selected = new Map();
  let schemaChars = 0;
  for (const { tool, score } of ranked) {
    if (score < floor || score <= 0) continue;
    // A selected action must retain its authorized prerequisites within the same budget.
    const group = new Map();
    const visit = (name) => {
      if (selected.has(name) || group.has(name)) return true;
      const candidate = catalog.get(name);
      if (!candidate) return false;
      group.set(name, candidate);
      return (candidate.meta?.requires || []).every(visit);
    };
    if (!visit(tool.name)) continue;
    const additionalChars = [...group.values()].reduce(
      (sum, candidate) => sum + JSON.stringify(ollamaTools([candidate])).length,
      0,
    );
    if (selected.size + group.size > limit || schemaChars + additionalChars > MAX_TOOL_SCHEMA_CHARS)
      continue;
    for (const [name, candidate] of group) selected.set(name, candidate);
    schemaChars += additionalChars;
  }
  return { tools: [...selected.values()], schemaChars };
}

export async function selectTools({
  tools,
  question,
  config,
  scope,
  signal,
  onProgress = () => {},
  cache = sharedCache,
  request = requestOllama,
}) {
  const configuredLimit = Number(config.tool_limit);
  const limit = Number.isInteger(configuredLimit) ? Math.max(3, Math.min(16, configuredLimit)) : 8;
  const configuredScore = Number(config.embedding_min_score);
  const minScore = Number.isFinite(configuredScore)
    ? Math.max(0, Math.min(1, configuredScore))
    : 0.35;
  const query = question.slice(-2400);
  let ranked;
  let mode = 'keyword';
  let warning = '';
  let cacheHits = 0;
  let embedded = 0;
  let floor = Number.EPSILON;
  signal?.throwIfAborted();
  if (config.embedding_model && tools.length) {
    try {
      const url = localUrl(config.url);
      const model = config.embedding_model;
      const tagTimeout = AbortSignal.timeout(15000);
      const tagSignal = signal ? AbortSignal.any([signal, tagTimeout]) : tagTimeout;
      const tags = await request(url, '/api/tags', null, null, tagSignal);
      const canonical = (name) => (name.includes(':') ? name : `${name}:latest`);
      const installed = tags.models?.find((item) => canonical(item.name) === canonical(model));
      if (!installed?.digest) throw new Error('Embedding-Modell nicht installiert.');
      const namespace = await fingerprint(['tool-index-v1', scope, url, model, installed.digest]);
      const ids = await Promise.all(
        tools.map(async (tool) => `${namespace}:${await fingerprint(tool)}`),
      );
      const cached = await cache.getMany(ids);
      const vectors = new Map();
      const embed = async (input) => {
        signal?.throwIfAborted();
        const timeout = AbortSignal.timeout(120000);
        const requestSignal = signal ? AbortSignal.any([signal, timeout]) : timeout;
        const result = await request(
          url,
          '/api/embed',
          { model, input, truncate: false, keep_alive: '15m' },
          null,
          requestSignal,
        );
        if (!Array.isArray(result.embeddings) || result.embeddings.length !== input.length)
          throw new Error('Unvollständige Embedding-Antwort.');
        return result.embeddings;
      };
      const [queryResult] = await embed([embeddingInput(model, query, true)]);
      const queryVector = normalized(queryResult);
      const missing = [];
      for (let index = 0; index < tools.length; index += 1) {
        try {
          vectors.set(ids[index], normalized(cached.get(ids[index]), queryVector.length));
          cacheHits += 1;
        } catch {
          missing.push(index);
        }
      }
      for (let offset = 0; offset < missing.length; offset += EMBEDDING_BATCH_SIZE) {
        signal?.throwIfAborted();
        onProgress(`Werkzeugindex: ${cacheHits + embedded} von ${tools.length} bereit …`);
        const batch = missing.slice(offset, offset + EMBEDDING_BATCH_SIZE);
        const result = await embed(
          batch.map((index) => embeddingInput(model, description(tools[index]), false)),
        );
        const entries = batch.map((index, position) => [
          ids[index],
          normalized(result[position], queryVector.length),
        ]);
        for (const [id, vector] of entries) vectors.set(id, vector);
        await cache.putMany(entries);
        embedded += entries.length;
      }
      ranked = tools.map((tool, index) => ({
        tool,
        score: queryVector.reduce(
          (sum, value, dimension) => sum + value * vectors.get(ids[index])[dimension],
          0,
        ),
      }));
      floor = Math.max(minScore, Math.max(...ranked.map((item) => item.score)) - 0.15);
      mode = 'semantic';
    } catch (error) {
      if (signal?.aborted) throw error;
      warning = 'Embedding-Auswahl nicht verfügbar; begrenzte Stichwortsuche aktiv.';
    }
  }
  signal?.throwIfAborted();
  ranked ||= lexicalScores(tools, query);
  ranked.sort((a, b) => b.score - a.score || a.tool.name.localeCompare(b.tool.name));
  return {
    ...boundedSelection(ranked, tools, limit, floor),
    mode,
    warning,
    total: tools.length,
    cacheHits,
    embedded,
    scores: ranked.map(({ tool, score }) => ({ name: tool.name, score })),
  };
}
