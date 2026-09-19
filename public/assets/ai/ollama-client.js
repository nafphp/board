// Adapted from naf/cms (MIT); see /assets/ai/LICENSE.
export const trimValue = (value) => String(value ?? '').trim();

export const normalizeOllamaBaseUrl = (value) => {
  const normalized = trimValue(value).replace(/\/+$/, '');
  if (normalized === '') {
    return 'http://localhost:11434';
  }

  if (/^https?:\/\//i.test(normalized)) {
    return normalized;
  }

  if (/^(localhost|\[[\d:a-f]+\]|(?:\d{1,3}\.){3}\d{1,3})(?::\d+)?$/i.test(normalized)) {
    return `http://${normalized}`;
  }

  return normalized;
};

const parseJsonLine = (line) => {
  try {
    return JSON.parse(line);
  } catch (error) {
    if (line !== '') throw new Error('Ollama hat ungültige Streaming-Daten geliefert.');
    return null;
  }
};

export const stripModelReasoning = (value) =>
  String(value ?? '')
    .replace(/<think>[\s\S]*?<\/think>/gi, '')
    .replace(/^\s*```(?:\w+)?\s*/g, '')
    .replace(/\s*```\s*$/g, '')
    .trim();

export const requestOllama = async (
  baseUrl,
  path,
  payload = null,
  onDelta = null,
  signal = null,
) => {
  const url = `${normalizeOllamaBaseUrl(baseUrl)}${path}`;
  const stream = typeof onDelta === 'function';
  const response = await fetch(url, {
    signal,
    credentials: 'omit',
    redirect: 'error',
    method: payload === null ? 'GET' : 'POST',
    headers: {
      Accept: 'application/json',
      ...(payload === null ? {} : { 'Content-Type': 'application/json' }),
    },
    body:
      payload === null
        ? undefined
        : JSON.stringify({
            ...payload,
            stream,
          }),
  }).catch((error) => {
    if (error.name === 'AbortError') throw error;
    throw new Error(
      `Could not reach Ollama at ${normalizeOllamaBaseUrl(baseUrl)}. ${error.message || 'Check the URL and make sure Ollama is running.'}`,
    );
  });

  if (!response.ok) {
    const rawMessage = await response.text().catch(() => '');
    let message = rawMessage;
    try {
      const parsed = JSON.parse(rawMessage);
      message = String(parsed.error || parsed.message || rawMessage);
    } catch (error) {}

    throw new Error(message || `Ollama returned HTTP ${response.status}.`);
  }

  if (!stream || !response.body) {
    return response.json();
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let finalPayload = {};
  let accumulatedResponse = '';
  const accumulatedMessage = {
    role: 'assistant',
    content: '',
    thinking: '',
    tool_calls: [],
  };

  const applyStreamPayload = (payloadLine) => {
    if (payloadLine.error) throw new Error(String(payloadLine.error));
    finalPayload = payloadLine;

    const responseDelta = String(payloadLine.response ?? '');
    const contentDelta = String(payloadLine.message?.content ?? '');
    const thinkingDelta = String(payloadLine.message?.thinking ?? '');
    if (responseDelta !== '') {
      accumulatedResponse += responseDelta;
    }
    if (contentDelta !== '') {
      accumulatedMessage.content += contentDelta;
    }
    if (thinkingDelta !== '') {
      accumulatedMessage.thinking += thinkingDelta;
    }
    if (Array.isArray(payloadLine.message?.tool_calls)) {
      accumulatedMessage.tool_calls.push(...payloadLine.message.tool_calls);
    }

    const delta = responseDelta || contentDelta;
    if (delta !== '') {
      onDelta(delta);
    }
  };

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';

    for (const line of lines) {
      const payloadLine = parseJsonLine(line.trim());
      if (!payloadLine) continue;

      applyStreamPayload(payloadLine);
    }
  }

  const tail = parseJsonLine(buffer.trim());
  if (tail) {
    applyStreamPayload(tail);
  }

  if (accumulatedResponse !== '') {
    finalPayload.response = accumulatedResponse;
  }
  if (
    accumulatedMessage.content !== '' ||
    accumulatedMessage.thinking !== '' ||
    accumulatedMessage.tool_calls.length > 0
  ) {
    finalPayload.message = {
      ...(finalPayload.message || {}),
      role: finalPayload.message?.role || accumulatedMessage.role,
      content: accumulatedMessage.content,
      thinking: accumulatedMessage.thinking,
      tool_calls: accumulatedMessage.tool_calls,
    };
  }

  return finalPayload;
};
