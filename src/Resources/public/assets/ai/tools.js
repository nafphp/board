// Adapted from naf/cms (MIT); see /assets/ai/LICENSE.
import { trimValue } from './ollama-client.js';

const sanitizeOllamaSchema = (schema) => {
  const normalized =
    schema && typeof schema === 'object' && !Array.isArray(schema) ? { ...schema } : {};

  if (normalized.type === 'object' || normalized.properties !== undefined) {
    normalized.type = 'object';
    normalized.properties =
      normalized.properties &&
      typeof normalized.properties === 'object' &&
      !Array.isArray(normalized.properties)
        ? Object.fromEntries(
            Object.entries(normalized.properties).map(([key, value]) => [
              key,
              sanitizeOllamaSchema(value),
            ]),
          )
        : {};
    normalized.additionalProperties = normalized.additionalProperties === true;
  }

  if (normalized.type === 'array') {
    normalized.items = sanitizeOllamaSchema(normalized.items || {});
  }

  if (Array.isArray(normalized.required) && normalized.required.length === 0) {
    delete normalized.required;
  }

  return normalized;
};

export const ollamaTools = (tools) =>
  tools
    .filter((tool) => tool && trimValue(tool.name) !== '')
    .map((tool) => ({
      type: 'function',
      function: {
        name: trimValue(tool.name),
        description: trimValue(tool.description),
        parameters: sanitizeOllamaSchema(
          tool.inputSchema || {
            type: 'object',
            properties: {},
            additionalProperties: false,
          },
        ),
      },
    }));

const normalizeToolArguments = (value) => {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return value;
  }

  if (typeof value === 'string' && trimValue(value) !== '') {
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  return {};
};

export const normalizeToolCalls = (response) => {
  const calls = response?.message?.tool_calls;
  return Array.isArray(calls)
    ? calls
        .map((call) => ({
          name: trimValue(call?.function?.name),
          arguments: normalizeToolArguments(call?.function?.arguments),
        }))
        .filter((call) => call.name !== '')
    : [];
};
