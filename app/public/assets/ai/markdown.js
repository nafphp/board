// Adapted from naf/cms (MIT); see /assets/ai/LICENSE.
import { trimValue } from './ollama-client.js';

export const escapeHtml = (value) =>
  String(value ?? '').replace(
    /[&<>"']/g,
    (char) =>
      ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
      })[char],
  );

const inlineMarkdown = (value) =>
  escapeHtml(value)
    .replace(
      /`([^`]+)`/g,
      '<code class="rounded bg-slate-200/70 px-1.5 py-0.5 text-[0.82em] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">$1</code>',
    )
    .replace(
      /\*\*([^*]+)\*\*/g,
      '<strong class="font-semibold text-slate-900 dark:text-white">$1</strong>',
    )
    .replace(/\*([^*]+)\*/g, '<em>$1</em>');

const splitTableRow = (line) =>
  trimValue(line)
    .replace(/^\|/, '')
    .replace(/\|$/, '')
    .split('|')
    .map((cell) => trimValue(cell));

const isTableDivider = (line) => /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line);

const isListLine = (line) => /^\s*([-*]|\d+\.)\s+/.test(line);

const normalizeAssistantContent = (content) => String(content ?? '').replace(/<br\s*\/?>/gi, '\n');

const renderMarkdownTable = (lines) => {
  const header = splitTableRow(lines[0]);
  const rows = lines
    .slice(2)
    .map(splitTableRow)
    .filter((row) => row.some((cell) => cell !== ''));
  if (header.length === 0 || rows.length === 0) {
    return `<p>${inlineMarkdown(lines.join('\n'))}</p>`;
  }

  const cells = (row, tag, className) =>
    row.map((cell) => `<${tag} class="${className}">${inlineMarkdown(cell)}</${tag}>`).join('');

  return [
    '<div class="my-3 overflow-x-auto rounded-lg border border-slate-200 bg-white/60 dark:border-slate-700 dark:bg-slate-950/40">',
    '<table class="min-w-full border-collapse text-left text-xs leading-5">',
    `<thead class="bg-slate-100/80 text-slate-700 dark:bg-slate-800/80 dark:text-slate-100"><tr>${cells(header, 'th', 'border-b border-slate-200 px-3 py-2 font-semibold dark:border-slate-700')}</tr></thead>`,
    `<tbody>${rows.map((row) => `<tr class="border-t border-slate-100 dark:border-slate-800">${cells(row, 'td', 'px-3 py-2 align-top text-slate-600 dark:text-slate-300')}</tr>`).join('')}</tbody>`,
    '</table>',
    '</div>',
  ].join('');
};

const renderMarkdownList = (lines, ordered) => {
  const tag = ordered ? 'ol' : 'ul';
  const markerClass = ordered ? 'list-decimal' : 'list-disc';
  const items = lines
    .map((line) => line.replace(/^\s*([-*]|\d+\.)\s+/, ''))
    .map((line) => `<li>${inlineMarkdown(line)}</li>`)
    .join('');

  return `<${tag} class="my-2 ${markerClass} space-y-1 pl-5">${items}</${tag}>`;
};

const renderMarkdown = (content) => {
  const lines = normalizeAssistantContent(content).replace(/\r\n?/g, '\n').split('\n');
  const html = [];
  let index = 0;

  while (index < lines.length) {
    const line = lines[index];

    if (trimValue(line) === '') {
      index += 1;
      continue;
    }

    if (line.startsWith('```')) {
      const code = [];
      index += 1;
      while (index < lines.length && !lines[index].startsWith('```')) {
        code.push(lines[index]);
        index += 1;
      }
      index += index < lines.length ? 1 : 0;
      html.push(
        `<pre class="my-3 max-h-72 overflow-auto rounded-lg bg-slate-950 p-3 text-xs leading-5 text-slate-100"><code>${escapeHtml(code.join('\n'))}</code></pre>`,
      );
      continue;
    }

    if (line.includes('|') && lines[index + 1] && isTableDivider(lines[index + 1])) {
      const table = [line, lines[index + 1]];
      index += 2;
      while (index < lines.length && trimValue(lines[index]) !== '' && lines[index].includes('|')) {
        table.push(lines[index]);
        index += 1;
      }
      html.push(renderMarkdownTable(table));
      continue;
    }

    const heading = line.match(/^(#{1,3})\s+(.+)$/);
    if (heading) {
      const levelClass = heading[1].length === 1 ? 'text-base' : 'text-sm';
      html.push(
        `<strong class="mt-3 mb-1 block ${levelClass} font-semibold text-slate-900 dark:text-white">${inlineMarkdown(heading[2])}</strong>`,
      );
      index += 1;
      continue;
    }

    if (isListLine(line)) {
      const ordered = /^\s*\d+\.\s+/.test(line);
      const list = [];
      while (
        index < lines.length &&
        isListLine(lines[index]) &&
        /^\s*\d+\.\s+/.test(lines[index]) === ordered
      ) {
        list.push(lines[index]);
        index += 1;
      }
      html.push(renderMarkdownList(list, ordered));
      continue;
    }

    const paragraph = [];
    while (
      index < lines.length &&
      trimValue(lines[index]) !== '' &&
      !lines[index].startsWith('```') &&
      !(lines[index].includes('|') && lines[index + 1] && isTableDivider(lines[index + 1])) &&
      !isListLine(lines[index])
    ) {
      paragraph.push(lines[index]);
      index += 1;
    }
    html.push(`<p class="my-2">${inlineMarkdown(paragraph.join(' '))}</p>`);
  }

  return html.join('');
};

export const renderMessageContent = (node, role, content) => {
  if (!(node instanceof HTMLElement)) {
    return;
  }

  if (role === 'assistant') {
    node.innerHTML = renderMarkdown(content);
    return;
  }

  node.textContent = content;
};
