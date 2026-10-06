// Optional local benchmark. Catalogs must come from authorized /ai/tools responses.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import {
  selectTools,
  MAX_TOOL_SCHEMA_CHARS,
} from '../../src/Resources/public/assets/ai/tool-router.js';
import { MemoryVectorCache } from '../../src/Resources/public/assets/ai/tool-index.js';
import { requestOllama } from '../../src/Resources/public/assets/ai/ollama-client.js';

if (!process.argv[2])
  throw new Error('Pass an authorized catalog array or {owner, viewer} catalog JSON.');
const input = JSON.parse(await readFile(process.argv[2], 'utf8'));
const catalogs = Array.isArray(input) ? { owner: input } : input;
assert.ok(Array.isArray(catalogs.owner));
const targetSize = Number(process.env.NAF_BENCH_CATALOG_SIZE || catalogs.owner.length);
const repetitions = Number(process.env.NAF_BENCH_RUNS || 3);
assert.ok(
  Number.isInteger(targetSize) && targetSize >= catalogs.owner.length && targetSize <= 2000,
);
assert.ok(Number.isInteger(repetitions) && repetitions >= 1 && repetitions <= 20);
const models = (
  process.env.NAF_BENCH_MODELS || 'embeddinggemma:latest,nomic-embed-text:latest'
).split(',');
const url = process.env.NAF_BENCH_OLLAMA_URL || 'http://localhost:11434';
const synthetic = Array.from({ length: targetSize - catalogs.owner.length }, (_, index) => ({
  name: `fixture_measurement_${index}`,
  description: `Read ${['soil chemistry', 'telescope coordinates', 'orchestra acoustics', 'warehouse battery charge', 'aquarium salinity', 'botanical specimen taxonomy'][index % 6]} from station ${index}. Returns measurement records.`,
  inputSchema: { type: 'object', properties: { station: { type: 'integer' } } },
  meta: { title: `Station measurement ${index}`, risk: 'read' },
}));
const cases = [
  [
    'de-board',
    'Welche Spalten und offenen Aufgaben gibt es auf unserem Board?',
    ['nafinity_board'],
  ],
  ['en-board', 'Read the columns and open tasks on the project board.', ['nafinity_board']],
  [
    'de-create',
    'Lege ein neues Ticket mit dem Titel Prototyp prüfen in Offen an.',
    ['nafinity_ticket_create', 'nafinity_board'],
  ],
  [
    'en-create',
    'Create a new ticket titled Check prototype in the Open column.',
    ['nafinity_ticket_create', 'nafinity_board'],
  ],
  [
    'de-edit',
    'Ändere Titel und Priorität des Tickets. Lies vorher seinen aktuellen Stand.',
    ['nafinity_ticket_update', 'nafinity_board', 'nafinity_ticket'],
  ],
  [
    'en-edit',
    'Update the title and priority of a ticket after reading its current version.',
    ['nafinity_ticket_update', 'nafinity_board', 'nafinity_ticket'],
  ],
  [
    'de-multistep',
    'Verschiebe das Ticket nach Review und schreibe einen Kommentar mit dem Ergebnis.',
    ['nafinity_ticket_move', 'nafinity_comment', 'nafinity_board', 'nafinity_ticket'],
  ],
  [
    'en-multistep',
    'Move the ticket to Review and add a comment with the result.',
    ['nafinity_ticket_move', 'nafinity_comment', 'nafinity_board', 'nafinity_ticket'],
  ],
  ['de-activity', 'Zeige die letzten Änderungen im Projektverlauf.', ['nafinity_activity']],
  ['en-activity', 'Read the recent activity of the project.', ['nafinity_activity']],
];
const cache = new MemoryVectorCache();
const results = [];
const tags = await requestOllama(url, '/api/tags', null);
const modelMetadata = tags.models
  .filter((item) => models.includes(item.name))
  .map(({ name, digest }) => ({ name, digest }));
async function measure({ model, actor, caseId, question, expected, run, fallback = false }) {
  const native = catalogs[actor];
  const tools = actor === 'owner' ? [...native, ...synthetic] : native;
  let inputs = 0;
  const request = async (...args) => {
    if (fallback) throw new Error('Measured offline fallback');
    if (args[1] === '/api/embed') inputs += args[2].input.length;
    return requestOllama(...args);
  };
  const start = performance.now();
  const selected = await selectTools({
    tools,
    question,
    config: { url, embedding_model: model, tool_limit: 8, embedding_min_score: 0.35 },
    scope: `live-benchmark:${actor}:project-1`,
    cache,
    request,
  });
  const milliseconds = performance.now() - start;
  const names = selected.tools.map((tool) => tool.name);
  assert.ok(names.length <= 8 && selected.schemaChars <= MAX_TOOL_SCHEMA_CHARS);
  for (const tool of selected.tools)
    for (const prerequisite of tool.meta?.requires || []) assert.ok(names.includes(prerequisite));
  if (actor === 'viewer') assert.ok(selected.tools.every((tool) => tool.meta?.risk === 'read'));
  results.push({
    model,
    actor,
    case: caseId,
    run,
    mode: selected.mode,
    milliseconds: Math.round(milliseconds * 100) / 100,
    expected,
    selected: names,
    hits: expected.filter((name) => names.includes(name)).length,
    complete: expected.every((name) => names.includes(name)),
    cache_hits: selected.cacheHits,
    newly_indexed: selected.embedded,
    embedding_inputs: inputs,
    schema_chars: selected.schemaChars,
    warning: selected.warning,
  });
}
for (const model of models) {
  for (let run = 0; run < repetitions; run += 1)
    for (const [caseId, question, expected] of cases)
      await measure({ model, actor: 'owner', caseId, question, expected, run });
  if (catalogs.viewer)
    await measure({
      model,
      actor: 'viewer',
      caseId: 'rights-removed',
      question: 'Read the board, move a ticket and add a comment.',
      expected: ['nafinity_board', 'nafinity_ticket'],
      run: 0,
    });
}
for (const [caseId, question, expected] of cases)
  await measure({
    model: models[0],
    actor: 'owner',
    caseId: `fallback-${caseId}`,
    question,
    expected,
    run: 0,
    fallback: true,
  });
const percentile = (values, fraction) =>
  values.sort((a, b) => a - b)[Math.ceil(values.length * fraction) - 1];
const summaries = [...new Set(results.map((r) => r.model + ':' + r.mode + ':' + r.actor))].map(
  (key) => {
    const group = results.filter((r) => r.model + ':' + r.mode + ':' + r.actor === key);
    const warm = group.filter((r) => r.newly_indexed === 0);
    return {
      key,
      cases: group.length,
      complete: group.filter((r) => r.complete).length,
      expected_hits: group.reduce((n, r) => n + r.hits, 0),
      expected_total: group.reduce((n, r) => n + r.expected.length, 0),
      warm_p50_ms:
        percentile(
          warm.map((r) => r.milliseconds),
          0.5,
        ) ?? null,
      warm_p95_ms:
        percentile(
          warm.map((r) => r.milliseconds),
          0.95,
        ) ?? null,
    };
  },
);
console.log(
  JSON.stringify(
    {
      recorded_at: new Date().toISOString(),
      node: process.version,
      url,
      models: modelMetadata,
      native_tools: catalogs.owner.length,
      viewer_tools: catalogs.viewer?.length ?? null,
      synthetic_tools: synthetic.length,
      catalog_size: targetSize,
      repetitions,
      summaries,
      results,
      note: 'Measures authorized tool selection and prerequisites, not model-generated arguments or executed writes. Synthetic distractors are not implemented actions. No writes are executed.',
    },
    null,
    2,
  ),
);
