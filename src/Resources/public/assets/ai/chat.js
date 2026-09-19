import { requestOllama } from './ollama-client.js';
import { renderMessageContent } from './markdown.js';
import { normalizeToolCalls, ollamaTools } from './tools.js';
import { api, configFor, localUrl, read, storageFor, systemPrompt, write } from './store.js';
import { initSettings } from './settings.js';
import { selectTools } from './tool-router.js';

document.querySelectorAll('[data-ai-settings]').forEach(initSettings);
const root = document.querySelector('[data-ai-chat]');
if (root) initChat(root);

function initChat(root) {
  const keys = storageFor(root);
  const panel = root.querySelector('[data-ai-panel]');
  const toggle = root.querySelector('[data-ai-toggle]');
  const form = root.querySelector('[data-ai-form]');
  const input = form.querySelector('textarea');
  const messageList = root.querySelector('[data-ai-messages]');
  const status = root.querySelector('[data-ai-status]');
  const send = root.querySelector('[data-ai-send]');
  const stop = root.querySelector('[data-ai-stop]');
  const confirmation = root.querySelector('[data-ai-confirmation]');
  const panelContent = root.querySelector('[data-ai-panel-content]');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  let expanded = false;
  let motionVersion = 0;
  let panelAnimation;
  let contentAnimation;
  const project = Number(root.dataset.project) || null;
  let history = read(keys.messages, [])
    .filter(
      (message) =>
        ['user', 'assistant'].includes(message.role) && typeof message.content === 'string',
    )
    .slice(-40);
  let controller;
  let busy = false;
  let epoch = 0;
  input.value = read(keys.draft, '');
  function save() {
    write(keys.messages, history.slice(-40));
  }
  function setStatus(text) {
    status.textContent = text;
    status.hidden = !text;
    status.classList.toggle('sr-only', text === 'Bereit' || text.startsWith('Bereit ·'));
  }
  function fitInput() {
    input.style.height = 'auto';
    input.style.height = `${Math.max(48, Math.min(132, input.scrollHeight))}px`;
  }
  function syncComposer() {
    send.disabled = busy || !input.value.trim();
    send.hidden = busy;
    stop.hidden = !busy;
    root.dataset.busy = String(busy);
    form.setAttribute('aria-busy', String(busy));
    fitInput();
  }
  function scroll() {
    messageList.scrollTop = history.length ? messageList.scrollHeight : 0;
  }
  function feedback(question, answer, button) {
    try {
      write(
        keys.feedback,
        [...read(keys.feedback, []), { question, answer, date: new Date().toISOString() }].slice(
          -20,
        ),
      );
      button.disabled = true;
      button.textContent = 'Feedback gespeichert';
      window.dispatchEvent(new CustomEvent('nafinity:ai-feedback'));
    } catch (error) {
      setStatus(error.message);
    }
  }
  function message(role, content, question = '') {
    const item = document.createElement('article');
    item.className = `ai-message ai-message-${role}`;
    const body = document.createElement('div');
    body.className = 'ai-message-content';
    renderMessageContent(body, role, content);
    item.append(body);
    if (role === 'assistant' && content) {
      const actions = document.createElement('div');
      actions.className = 'ai-message-actions';
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.textContent = 'Kopieren';
      copy.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(content);
          setStatus('Antwort kopiert.');
        } catch {
          setStatus('Kopieren ist in diesem Browser nicht verfügbar.');
        }
      });
      const review = document.createElement('button');
      review.type = 'button';
      review.textContent = 'Feedback';
      review.addEventListener('click', () => feedback(question, content, review));
      actions.append(copy, review);
      item.append(actions);
    }
    messageList.append(item);
    return { item, body };
  }
  function render() {
    messageList.replaceChildren();
    if (!history.length) {
      const welcome = root.querySelector('[data-ai-welcome]').content.cloneNode(true);
      welcome.querySelectorAll('[data-ai-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
          input.value = button.dataset.aiPrompt;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.focus({ preventScroll: true });
        });
      });
      messageList.append(welcome);
    }
    let question = '';
    for (const entry of history) {
      if (entry.role === 'user') question = entry.content;
      message(entry.role, entry.content, question);
    }
    scroll();
  }
  async function open(value) {
    if (value === expanded) return;
    expanded = value;
    const version = ++motionVersion;
    const wasHidden = panel.hidden;
    panel.hidden = false;
    panel.inert = !value;
    root.dataset.open = 'true';
    toggle.tabIndex = -1;
    toggle.setAttribute('aria-expanded', String(value));

    // Reveal the real-sized content through a growing shape; text is never scaled.
    const frame = panel.getBoundingClientRect();
    const button = toggle.getBoundingClientRect();
    const iconRadius = getComputedStyle(toggle).borderRadius;
    const fullRadius = matchMedia('(max-width: 600px)').matches ? '20px' : '22px';
    const iconClip = `inset(${button.top - frame.top}px ${frame.right - button.right}px ${frame.bottom - button.bottom}px ${button.left - frame.left}px round ${iconRadius})`;
    const fullClip = `inset(0px 0px 0px 0px round ${fullRadius})`;
    const currentClip = getComputedStyle(panel).clipPath;
    const fromClip = wasHidden ? iconClip : currentClip === 'none' ? fullClip : currentClip;
    const fromOpacity = wasHidden ? 0 : Number(getComputedStyle(panelContent).opacity);
    const fromRadius = getComputedStyle(panel).borderRadius;
    panelAnimation?.cancel();
    contentAnimation?.cancel();

    if (value) {
      syncComposer();
      scroll();
      if (matchMedia('(pointer: fine)').matches) input.focus({ preventScroll: true });
      else panel.focus({ preventScroll: true });
    }
    if (!reducedMotion.matches) {
      const duration = value ? 420 : 280;
      panelAnimation = panel.animate(
        [
          { clipPath: fromClip, borderRadius: wasHidden ? iconRadius : fromRadius },
          { clipPath: value ? fullClip : iconClip, borderRadius: value ? fullRadius : iconRadius },
        ],
        {
          duration,
          easing: value ? 'cubic-bezier(.16,1,.3,1)' : 'cubic-bezier(.4,0,.2,1)',
          fill: 'both',
        },
      );
      contentAnimation = panelContent.animate(
        value
          ? [{ opacity: fromOpacity }, { opacity: fromOpacity, offset: 0.2 }, { opacity: 1 }]
          : [{ opacity: fromOpacity }, { opacity: 0, offset: 0.55 }, { opacity: 0 }],
        { duration, easing: 'ease-out', fill: 'both' },
      );
      await panelAnimation.finished.catch(() => {});
    }
    if (version !== motionVersion) return;
    panel.hidden = !value;
    root.dataset.open = String(value);
    panelAnimation?.cancel();
    contentAnimation?.cancel();
    if (!value) {
      toggle.tabIndex = 0;
      toggle.focus({ preventScroll: true });
    }
  }
  function synchronize() {
    controller?.abort();
    epoch += 1;
    const config = configFor(root);
    root.hidden = !config.enabled;
    if (root.hidden) {
      motionVersion += 1;
      expanded = false;
      panelAnimation?.cancel();
      contentAnimation?.cancel();
      panel.hidden = true;
      panel.inert = false;
      root.dataset.open = 'false';
      toggle.tabIndex = 0;
      toggle.setAttribute('aria-expanded', 'false');
    }
  }
  toggle.addEventListener('click', () => open(!expanded));
  root.querySelector('[data-ai-close]').addEventListener('click', () => open(false));
  panel.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && confirmation.hidden) {
      event.preventDefault();
      open(false);
    }
  });
  input.addEventListener('input', () => {
    syncComposer();
    try {
      write(keys.draft, input.value);
    } catch (error) {
      setStatus(error.message);
    }
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      if (!busy) form.requestSubmit();
    }
  });
  root.querySelector('[data-ai-new]').addEventListener('click', () => {
    controller?.abort();
    epoch += 1;
    history = [];
    setStatus('');
    try {
      save();
      write(keys.draft, '');
      input.value = '';
    } catch (error) {
      setStatus(error.message);
    }
    root.querySelector('[data-ai-routing]').hidden = true;
    syncComposer();
    render();
    input.focus({ preventScroll: true });
  });
  stop.addEventListener('click', () => controller?.abort());
  window.addEventListener('nafinity:ai-settings', synchronize);
  window.addEventListener('storage', (event) => {
    if (event.key === keys.config || event.key === keys.memory) synchronize();
  });
  synchronize();
  syncComposer();
  render();

  function approve(call, definition, signal) {
    return new Promise((resolve) => {
      confirmation.replaceChildren();
      confirmation.hidden = false;
      const heading = document.createElement('strong');
      heading.textContent = `${definition.meta.title} – ausführen?`;
      const args = document.createElement('pre');
      args.textContent = JSON.stringify(call.arguments, null, 2);
      const controls = document.createElement('div');
      controls.className = 'ai-actions';
      const accept = document.createElement('button');
      accept.type = 'button';
      accept.className = 'button primary';
      accept.textContent = 'Änderung bestätigen';
      const reject = document.createElement('button');
      reject.type = 'button';
      reject.className = 'button subtle';
      reject.textContent = 'Ablehnen';
      const abort = () => finish(false);
      function finish(value) {
        signal.removeEventListener('abort', abort);
        confirmation.hidden = true;
        confirmation.replaceChildren();
        resolve(value);
      }
      accept.addEventListener('click', () => finish(true), { once: true });
      reject.addEventListener('click', () => finish(false), { once: true });
      signal.addEventListener('abort', abort, { once: true });
      controls.append(accept, reject);
      confirmation.append(heading, args, controls);
      reject.focus();
      setStatus('Bitte prüfe die vorgeschlagene Änderung.');
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const question = input.value.trim();
    if (busy || !question) return;
    const config = configFor(root);
    if (!config.enabled) return;
    try {
      config.url = localUrl(config.url);
      if (!config.model) throw new Error('Wähle zuerst ein Modell in den AI-Einstellungen.');
    } catch (error) {
      setStatus(error.message);
      return;
    }
    busy = true;
    syncComposer();
    controller = new AbortController();
    const signal = controller.signal;
    const turn = ++epoch;
    history.push({ role: 'user', content: question });
    input.value = '';
    fitInput();
    render();
    let pending;
    let completed = false;
    try {
      save();
      write(keys.draft, '');
      setStatus('Verbinde mit dem lokalen Modell …');
      const capabilities = await requestOllama(
        config.url,
        '/api/show',
        { model: config.model },
        null,
        signal,
      );
      if (!capabilities.capabilities?.includes('tools'))
        throw new Error(
          'Dieses Modell unterstützt keine Werkzeuge. Bitte wähle ein anderes Modell.',
        );
      const allTools = (
        await api('/ai/tools' + (project ? `?project=${project}` : ''), null, signal)
      ).tools;
      const previousQuestion = history
        .slice(0, -1)
        .findLast((entry) => entry.role === 'user')?.content;
      const refersBack =
        /^(ja\b|dann\b|und\b)|\b(dieses ticket|diese aufgabe|diese karte|that ticket|it|them)\b/i.test(
          question,
        );
      const searchQuestion =
        question.length < 120 && refersBack && previousQuestion
          ? `${previousQuestion.slice(-800)}\n${question}`
          : question;
      const selection = await selectTools({
        tools: allTools,
        question: searchQuestion,
        config,
        scope: keys.messages,
        signal,
        onProgress: setStatus,
      });
      const tools = selection.tools;
      const routing = root.querySelector('[data-ai-routing]');
      routing.hidden = false;
      routing.querySelector('[data-ai-routing-summary]').textContent =
        `${selection.mode === 'semantic' ? 'Semantische Auswahl' : 'Stichwortauswahl'} · ${tools.length} von ${selection.total} Werkzeugen`;
      routing.querySelector('[data-ai-routing-detail]').textContent = [
        selection.warning,
        tools.map((tool) => tool.meta?.title || tool.name).join(', ') ||
          'Kein passendes Werkzeug gefunden.',
        selection.mode === 'semantic' &&
          `Index: ${selection.cacheHits} wiederverwendet, ${selection.embedded} neu berechnet.`,
      ]
        .filter(Boolean)
        .join(' ');
      // Keep recent complete turns within a bounded local context.
      const recent = [];
      let remaining = 24000;
      for (const entry of [...history].reverse()) {
        if (entry.content.length > remaining && recent.length) break;
        recent.unshift({ role: entry.role, content: entry.content.slice(-remaining) });
        remaining -= Math.min(remaining, entry.content.length);
        if (!remaining) break;
      }
      const messages = [{ role: 'system', content: systemPrompt(config) }, ...recent];
      for (let round = 0; round < 8; round += 1) {
        signal.throwIfAborted();
        setStatus('Denkt nach …');
        pending = message('assistant', '');
        let content = '';
        const timeout = setTimeout(() => controller.abort(), 180000);
        let response;
        try {
          response = await requestOllama(
            config.url,
            '/api/chat',
            {
              model: config.model,
              messages,
              tools: ollamaTools(tools),
              keep_alive: '15m',
              options: { num_ctx: 8192, num_predict: 4096 },
            },
            root.querySelector('[data-ai-stream]').checked
              ? (delta) => {
                  const nearBottom =
                    messageList.scrollHeight - messageList.scrollTop - messageList.clientHeight <
                    80;
                  content += delta;
                  renderMessageContent(pending.body, 'assistant', content);
                  if (nearBottom) scroll();
                }
              : null,
            signal,
          );
        } finally {
          clearTimeout(timeout);
        }
        const calls = normalizeToolCalls(response);
        content = String(response.message?.content || content);
        pending.item.remove();
        pending = null;
        if (turn !== epoch) return;
        if (!calls.length) {
          if (!content.trim())
            throw new Error('Das Modell hat eine leere Antwort geliefert. Versuche es erneut.');
          history.push({ role: 'assistant', content: content.slice(0, 12000) });
          save();
          render();
          completed = true;
          setStatus('Bereit · ' + config.model);
          break;
        }
        messages.push({ role: 'assistant', content, tool_calls: response.message.tool_calls });
        for (const call of calls.slice(0, 8)) {
          signal.throwIfAborted();
          const definition = tools.find((tool) => tool.name === call.name);
          if (!definition)
            throw new Error('Das Modell hat ein nicht verfügbares Werkzeug angefragt.');
          const writes = definition.meta.risk !== 'read';
          if (writes && !(await approve(call, definition, signal))) {
            signal.throwIfAborted();
            history.push({
              role: 'assistant',
              content: 'Die vorgeschlagene Änderung wurde nicht ausgeführt.',
            });
            save();
            render();
            completed = true;
            setStatus('Änderung abgelehnt.');
            break;
          }
          signal.throwIfAborted();
          setStatus(definition.meta.title + ' …');
          const response = await api(
            '/ai/tools/call',
            { project, name: call.name, arguments: call.arguments, confirmed: writes },
            signal,
          );
          const result = JSON.stringify(response.result);
          messages.push({
            role: 'tool',
            tool_name: call.name,
            content:
              result.length > 24000
                ? result.slice(0, 24000) + '\n[Result truncated; request one ticket for details.]'
                : result,
          });
          if (writes) {
            history.push({ role: 'assistant', content: definition.meta.title + ': ausgeführt.' });
            save();
            message('status', definition.meta.title + ': ausgeführt.');
            document.dispatchEvent(new CustomEvent('nafinity:ai-changed'));
          }
        }
        if (completed) break;
      }
      if (!completed)
        setStatus(
          'Die maximale Anzahl an Werkzeugschritten ist erreicht. Stelle eine gezieltere Frage.',
        );
    } catch (error) {
      pending?.item.remove();
      if (turn === epoch)
        setStatus(
          signal.aborted
            ? 'Antwort gestoppt. Bereits bestätigte Änderungen bleiben erhalten.'
            : error.message,
        );
    } finally {
      busy = false;
      syncComposer();
      confirmation.hidden = true;
    }
  });
}
