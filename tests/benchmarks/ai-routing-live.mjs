// Optional local-model benchmark. Never part of the offline default test suite.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import { selectTools } from '../src/Resources/public/assets/ai/tool-router.js';
import { MemoryVectorCache } from '../src/Resources/public/assets/ai/tool-index.js';
import { requestOllama } from '../src/Resources/public/assets/ai/ollama-client.js';

if (!process.argv[2])
  throw new Error('Pass a JSON file containing an authorized /ai/tools catalog.');
const nativeTools = JSON.parse(await readFile(process.argv[2], 'utf8'));
const domains = [
  'telescope observations and celestial coordinates',
  'soil chemistry and laboratory samples',
  'irrigation valves and greenhouse humidity',
  'orchestra instruments and concert acoustics',
  'restaurant menus and ingredient nutrition',
  'warehouse forklift battery charge',
  'aviation weather measurements',
  'botanical specimen taxonomy',
  'geological rock sample mineral composition',
  'aquarium water salinity and fish feeding',
  'historical manuscript bibliography',
  'bicycle wheel spoke tension',
  'industrial furnace temperature sensors',
  'veterinary vaccination records',
  'solar inverter electrical measurements',
  'textile fabric dye measurements',
  'maritime buoy tidal measurements',
  'railway locomotive fuel consumption',
  'sculpture museum dimensions and materials',
  'archaeological excavation artifact coordinates',
];
const syntheticTools = Array.from({ length: 500 - nativeTools.length }, (_, index) => ({
  name: `fixture_${index}`,
  description: `Read ${domains[index % domains.length]} from station ${index}. Returns the latest measurement records.`,
  inputSchema: { type: 'object', properties: { station: { type: 'integer' } } },
  meta: { title: `${domains[index % domains.length]} ${index}`, risk: 'read' },
}));
const catalog = [...nativeTools, ...syntheticTools];
const cache = new MemoryVectorCache();
const config = {
  url: 'http://localhost:11434',
  embedding_model: 'embeddinggemma:latest',
  tool_limit: 8,
  embedding_min_score: 0.35,
};
const results = [];
let embeddedInputs = 0;
const request = async (...args) => {
  if (args[1] === '/api/embed') embeddedInputs += args[2].input.length;
  return requestOllama(...args);
};
for (const [question, expected] of [
  ['Welche Spalten gibt es auf dem Board unseres Projekts?', 'nafinity_board'],
  ['Lege ein neues Ticket mit dem Titel Prototyp prüfen in Offen an.', 'nafinity_ticket_create'],
]) {
  const before = embeddedInputs;
  const start = performance.now();
  const result = await selectTools({
    tools: catalog,
    question,
    config,
    scope: 'synthetic-live-benchmark',
    cache,
    request,
  });
  assert.equal(result.mode, 'semantic', result.warning);
  assert.ok(
    result.tools.some((tool) => tool.name === expected),
    JSON.stringify(result.scores.slice(0, 10)),
  );
  assert.ok(result.tools.length <= 8);
  if (results.length) {
    assert.equal(result.embedded, 0);
    assert.equal(embeddedInputs - before, 1);
  }
  results.push({
    question,
    milliseconds: Math.round(performance.now() - start),
    cache_hits: result.cacheHits,
    newly_indexed: result.embedded,
    embedding_inputs: embeddedInputs - before,
    selected: result.tools.map((tool) => tool.name),
    top_scores: result.scores.slice(0, 8),
  });
}
console.log(
  JSON.stringify(
    {
      model: config.embedding_model,
      catalog_size: catalog.length,
      native_tools: nativeTools.length,
      synthetic_tools: syntheticTools.length,
      results,
      note: 'Selection benchmark with synthetic tool metadata; these are not 500 implemented application actions.',
    },
    null,
    2,
  ),
);
