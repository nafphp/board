import { toast } from './app.js';
import { enhanceChoices, openChoice, closeChoices } from './ticket-choice.js';
import { attribute, fetchWorkspace, syncWidgets } from './fragment.js';

let quillReady;
const editors = new WeakMap();
const saving = new WeakMap();
const pendingForms = new WeakMap();
const inlineEdits = new WeakMap();
const editIntents = new WeakMap();
const revisionFields = new Set(['_csrf', 'version', 'board_revision']);

function values(form) {
  const editor = editors.get(form);
  if (editor) form.elements.description_html.value = editor.getSemanticHTML();
  return JSON.stringify([...new FormData(form)].filter(([name]) => !revisionFields.has(name)));
}
function inlineState(form) {
  if (!inlineEdits.has(form))
    inlineEdits.set(form, {
      baseline: values(form),
      savedForm: form.cloneNode(true),
      timer: null,
      composing: false,
    });
  return inlineEdits.get(form);
}
function inlineStatus(form, message) {
  if (!form.hasAttribute('data-auto-save')) return;
  const status = form.closest('.ticket-workspace')?.querySelector('.ticket-save-state');
  if (status) status.textContent = message;
}
function changed(form) {
  const modified = values(form) !== inlineState(form).baseline;
  form.dataset.dirty = String(modified);
  return modified;
}
function scheduleSave(form, delay = 700) {
  const state = inlineState(form);
  clearTimeout(state.timer);
  if (!changed(form)) {
    inlineStatus(form, 'Keine ungespeicherten Änderungen');
    return;
  }
  if (state.composing) return;
  if (form.dataset.conflict) {
    inlineStatus(form, 'Nicht gespeichert · Bitte aktuellen Stand laden');
    return;
  }
  inlineStatus(form, 'Änderungen werden gespeichert …');
  state.timer = setTimeout(() => flushInline(form), delay);
}
async function flushInline(form) {
  const state = inlineState(form);
  clearTimeout(state.timer);
  const workspace = form.closest('.ticket-workspace');
  if (!workspace || !form.isConnected || state.composing || workspace.dataset.needsReload)
    return false;
  // One request per ticket at a time; later edits use the revision returned by the first.
  if (saving.has(workspace)) await saving.get(workspace);
  if (!form.isConnected || workspace.dataset.needsReload) return false;
  if (!changed(form)) return true;
  if (form.dataset.conflict) return false;
  if (!form.checkValidity()) {
    inlineStatus(form, 'Bitte prüfe den eingegebenen Wert.');
    error(form, 'Bitte prüfe den eingegebenen Wert.');
    return false;
  }
  const saved = await saveTicket(form);
  return saved && !changed(form);
}
async function finishEdit(field, fromBlur = false) {
  if (!field?.classList.contains('is-editing')) return true;
  const form = field.querySelector('form');
  if (!(await flushInline(form))) return false;
  if (!field.classList.contains('is-editing')) return true;
  if (fromBlur && form.contains(document.activeElement)) return false;
  cancelEdit(field, !fromBlur && form.contains(document.activeElement));
  return true;
}

function loadQuill() {
  if (window.Quill) return Promise.resolve(window.Quill);
  if (!quillReady) {
    quillReady = new Promise((resolve, reject) => {
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = '/assets/vendor/quill/quill.snow.css';
      document.head.append(css);
      const script = document.createElement('script');
      script.src = '/assets/vendor/quill/quill.js';
      script.onload = () => resolve(window.Quill);
      script.onerror = () => {
        quillReady = null;
        script.remove();
        reject(new Error('Der Texteditor konnte nicht geladen werden. Bitte erneut versuchen.'));
      };
      document.head.append(script);
    });
  }
  return quillReady;
}
function error(form, message, conflict = false) {
  const box = form.querySelector('.form-errors');
  if (!box) return toast(message);
  box.replaceChildren(document.createTextNode(message));
  if (conflict) {
    const reload = document.createElement('a');
    reload.href = location.href;
    reload.textContent = 'Aktuellen Stand laden';
    box.append(document.createElement('br'), reload);
  }
  if (form.hasAttribute('data-auto-save') && !conflict) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'text-button';
    retry.dataset.retrySave = '';
    retry.textContent = 'Erneut versuchen';
    box.append(document.createTextNode(' '), retry);
  }
  box.classList.add('visible');
}
async function beginEdit(field) {
  const workspace = field.closest('.ticket-workspace');
  if (!field.querySelector('form') || field.classList.contains('is-editing')) return;
  const name = field.dataset.inlineField;
  const intent = Symbol();
  editIntents.set(workspace, intent);
  const active = workspace.querySelector('.is-editing');
  if (active && !(await finishEdit(active, true))) return;
  if (saving.has(workspace)) await saving.get(workspace);
  if (editIntents.get(workspace) !== intent || !workspace.isConnected) return;
  field = workspace.querySelector(`[data-inline-field="${attribute(name)}"]`);
  if (!field) return;
  const form = field.querySelector('form');
  field.classList.add('is-editing');
  field.querySelector('.inline-display').setAttribute('aria-expanded', 'true');
  form.hidden = false;
  inlineStatus(form, form.dataset.autoSave);
  if (form.dataset.conflict) {
    error(form, 'Das Ticket wurde inzwischen geändert. Bitte lade den aktuellen Stand.', true);
    inlineStatus(form, 'Nicht gespeichert · Bitte aktuellen Stand laden');
  }
  if (form.querySelector('[data-ticket-choice]')) {
    inlineState(form);
    openChoice(form.querySelector('[data-ticket-choice]'));
  } else if (form.querySelector('[data-rich-editor]')) {
    try {
      const editor = await initializeEditor(form);
      if (field.isConnected && field.classList.contains('is-editing')) {
        inlineState(form);
        editor?.focus();
      }
    } catch (exception) {
      error(form, exception.message);
    }
  } else {
    inlineState(form);
    const input = form.querySelector('input:not([type=hidden]), select, textarea:not([hidden])');
    resizeTitle(input);
    input?.focus();
    if (input?.name === 'title') input.select();
  }
}
async function initializeEditor(form) {
  if (editors.has(form)) return editors.get(form);
  const Quill = await loadQuill();
  if (!form.isConnected || saving.has(form.closest('.ticket-workspace'))) return;
  // Another caller may have awaited the same script load.
  if (editors.has(form)) return editors.get(form);
  const editorNode = form.querySelector('[data-rich-editor]');
  const editor = new Quill(editorNode, {
    theme: 'snow',
    formats: [
      'header',
      'bold',
      'italic',
      'underline',
      'strike',
      'list',
      'blockquote',
      'code-block',
      'code',
      'link',
    ],
    modules: {
      toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'bullet' }, { list: 'ordered' }],
        ['blockquote', 'code-block', 'link', 'clean'],
      ],
    },
    placeholder: 'Beschreibe das Ziel und die nächsten Schritte …',
  });
  const source = form.querySelector(
    'textarea[name="description_html"], textarea[name="description"]',
  );
  if (source.name === 'description') editor.setText(source.value);
  else editor.clipboard.dangerouslyPasteHTML(source.value);
  source.name = 'description_html';
  source.hidden = true;
  editor.history.clear();
  editor.root.setAttribute('aria-label', 'Beschreibung bearbeiten');
  editor.root.setAttribute('role', 'textbox');
  editor.root.setAttribute('aria-multiline', 'true');
  const labels = {
    bold: 'Fett',
    italic: 'Kursiv',
    underline: 'Unterstrichen',
    strike: 'Durchgestrichen',
    blockquote: 'Zitat',
    'code-block': 'Codeblock',
    link: 'Link',
    clean: 'Formatierung entfernen',
  };
  form.querySelectorAll('.ql-toolbar button').forEach((button) => {
    const format = [...button.classList].find((name) => name.startsWith('ql-'))?.slice(3);
    button.setAttribute(
      'aria-label',
      format === 'list'
        ? button.value === 'ordered'
          ? 'Nummerierte Liste'
          : 'Aufzählung'
        : labels[format] || format,
    );
    button.title = button.getAttribute('aria-label');
  });
  form.querySelector('.ql-picker-label')?.setAttribute('aria-label', 'Überschrift oder Fließtext');
  editor.on('text-change', (_delta, _old, source) => {
    if (source === 'user') {
      form.dataset.dirty = 'true';
      if (form.hasAttribute('data-auto-save')) scheduleSave(form);
    }
  });
  editors.set(form, editor);
  return editor;
}
function resizeTitle(input) {
  if (!input?.matches('textarea[name=title]')) return;
  input.style.height = 'auto';
  input.style.height = `${input.scrollHeight}px`;
}
function initializeCreation() {
  enhanceChoices();
  document.querySelectorAll('[data-ticket-create]').forEach((form) => {
    resizeTitle(form.elements.title);
    initializeEditor(form).catch((exception) => error(form, exception.message));
  });
}
window.addEventListener('resize', () => {
  document.querySelectorAll('.ticket-field textarea[name=title]').forEach((input) => {
    if (input.offsetParent) resizeTitle(input);
  });
});
document.addEventListener('nafinity:ticket-opened', initializeCreation);
initializeCreation();

function cancelEdit(field, focus = true) {
  const form = field.querySelector('form');
  if (saving.has(field.closest('.ticket-workspace'))) return;
  const state = inlineState(form);
  clearTimeout(state.timer);
  for (const input of form.elements) {
    if (!input.name || revisionFields.has(input.name)) continue;
    const saved = [...state.savedForm.elements].find(
      (item) =>
        item.name === input.name && (input.type !== 'checkbox' || item.value === input.value),
    );
    if (!saved) continue;
    if (input.type === 'checkbox') input.checked = saved.checked;
    else input.value = saved.value;
  }
  delete form.dataset.dirty;
  const editor = editors.get(form);
  if (editor) {
    editor.clipboard.dangerouslyPasteHTML(form.elements.description_html.value);
    editor.history.clear();
  }
  state.baseline = values(form);
  inlineStatus(
    form,
    form.dataset.conflict || field.closest('.ticket-workspace').dataset.needsReload
      ? 'Bitte aktuellen Stand laden'
      : 'Keine ungespeicherten Änderungen',
  );
  closeChoices(form);
  enhanceChoices(form);
  form.hidden = true;
  form.querySelector('.form-errors')?.classList.remove('visible');
  field.classList.remove('is-editing');
  const display = field.querySelector('.inline-display');
  display.setAttribute('aria-expanded', 'false');
  if (focus) display.focus();
}
export async function refresh(workspace, field, section) {
  const fresh = await fetchWorkspace(workspace);
  if (!fresh)
    throw new Error(
      'Gespeichert. Der aktuelle Stand konnte nicht geladen werden. Bitte lade die Seite neu.',
    );
  workspace.dataset.version = fresh.dataset.version;
  workspace.dataset.revision = fresh.dataset.revision;
  // The comment draft stays mounted while metadata or descriptions are saved.
  workspace.querySelectorAll('[data-inline-field]').forEach((current) => {
    if (current.classList.contains('is-editing') && current !== field) return;
    const replacement = fresh.querySelector(
      `[data-inline-field="${attribute(current.dataset.inlineField)}"]`,
    );
    if (replacement && current === field && current.querySelector('[data-auto-save]')) {
      const form = current.querySelector('form');
      const savedForm = replacement.querySelector('form');
      if (!savedForm)
        throw new Error('Der Bearbeitungszustand hat sich geändert. Bitte lade das Ticket neu.');
      inlineState(form).savedForm = savedForm;
      const display = replacement.querySelector('.inline-display');
      display.setAttribute('aria-expanded', 'true');
      current.querySelector('.inline-display').replaceWith(display);
    } else if (replacement) current.replaceWith(replacement);
  });
  workspace.querySelectorAll('[data-ticket-readonly]').forEach((current) => {
    const replacement = fresh.querySelector(
      `[data-ticket-readonly="${current.dataset.ticketReadonly}"]`,
    );
    if (replacement) current.replaceWith(replacement);
  });
  // Widgets are reconciled by id rather than by walking the ones already here,
  // so a widget an extension added appears and one it removed goes away. The
  // node holding an open draft is kept exactly as it is.
  syncWidgets(workspace, fresh, {
    keep: (node) => node.contains(field) && field?.classList.contains('is-editing'),
  });
  for (const name of new Set(section ? [section] : [])) {
    const current = workspace.querySelector(`[data-ticket-section="${attribute(name)}"]`);
    const replacement = fresh.querySelector(`[data-ticket-section="${attribute(name)}"]`);
    if (current && replacement && !current.closest('[data-widget-slot]')) {
      if (current.open) replacement.open = true;
      current.replaceWith(replacement);
    }
  }
  workspace.querySelectorAll('input[name=version]:not([data-comment-version])').forEach((input) => {
    input.value = fresh.dataset.version;
  });
  workspace.querySelectorAll('input[name=board_revision]').forEach((input) => {
    input.value = fresh.dataset.revision;
  });
  enhanceChoices(workspace);
  document.dispatchEvent(new CustomEvent('nafinity:ai-changed'));
}
document.addEventListener('nafinity:choice-dismiss', (event) => {
  const field = event.target.closest('[data-inline-field]');
  if (field?.classList.contains('is-editing')) finishEdit(field, !event.detail.focus);
});
document.addEventListener('click', (event) => {
  const display = event.target.closest('.inline-display[role=button]');
  if (display && !event.target.closest('a')) beginEdit(display.closest('[data-inline-field]'));
  const retry = event.target.closest('[data-retry-save]');
  if (retry) flushInline(retry.closest('form'));
  const reply = event.target.closest('[data-reply]');
  if (reply) {
    const form = reply.closest('.ticket-workspace').querySelector('[data-comment-composer]');
    if (!form || pendingForms.has(form)) return;
    const textarea = form.elements.body;
    const previous = form.dataset.replyHandle;
    if (previous && textarea.value.startsWith(`@${previous} `))
      textarea.value = textarea.value.slice(previous.length + 2);
    textarea.value = `@${reply.dataset.handle} ${textarea.value}`;
    form.elements.parent_id.value = reply.dataset.reply;
    form.dataset.replyHandle = reply.dataset.handle;
    form.dataset.dirty = 'true';
    const context = form.querySelector('.reply-context');
    context.hidden = false;
    context.querySelector('span').textContent = `Antwort an @${reply.dataset.handle}`;
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  const cancelReply = event.target.closest('[data-cancel-reply]');
  if (cancelReply) {
    const form = cancelReply.closest('form');
    form.elements.parent_id.value = '';
    form.querySelector('.reply-context').hidden = true;
    delete form.dataset.replyHandle;
    form.elements.body.focus();
  }
  if (!event.target.closest('.comment-composer'))
    document.querySelectorAll('.mention-list').forEach((list) => {
      list.hidden = true;
      list.closest('form').elements.body.setAttribute('aria-expanded', 'false');
    });
});
document.addEventListener('change', (event) => {
  const form = event.target.closest('[data-auto-save]');
  if (!form) return;
  if (event.target.tagName === 'SELECT') finishEdit(form.closest('[data-inline-field]'));
  else if (event.target.closest('[data-ticket-choice]')) flushInline(form);
  else scheduleSave(form);
});
document.addEventListener('focusout', (event) => {
  const form = event.target.closest('[data-auto-save]');
  if (!form) return;
  setTimeout(() => {
    if (form.isConnected && !form.contains(document.activeElement) && document.hasFocus())
      finishEdit(form.closest('[data-inline-field]'), true);
  }, 0);
});
document.addEventListener('compositionstart', (event) => {
  const form = event.target.closest('[data-auto-save]');
  if (form) {
    inlineState(form).composing = true;
    clearTimeout(inlineState(form).timer);
  }
});
document.addEventListener('compositionend', (event) => {
  const form = event.target.closest('[data-auto-save]');
  if (form) {
    inlineState(form).composing = false;
    scheduleSave(form);
  }
});
document.addEventListener('input', (event) => {
  resizeTitle(event.target);
  const form = event.target.closest('[data-ticket-form]');
  if (form?.hasAttribute('data-auto-save')) {
    scheduleSave(form);
  } else if (form) {
    form.dataset.dirty = String(
      !form.hasAttribute('data-comment-composer') ||
        !!event.target.value.trim() ||
        !!form.elements.parent_id.value,
    );
  }
  if (event.target.matches('[data-comment-composer] textarea')) mentionOptions(event.target);
});
document.addEventListener('keydown', (event) => {
  if (event.isComposing || event.defaultPrevented) return;
  const display = event.target.closest('.inline-display[role=button]');
  if (display && ['Enter', ' '].includes(event.key) && !event.target.closest('a')) {
    event.preventDefault();
    beginEdit(display.closest('[data-inline-field]'));
  }
  const active = event.target.closest('.is-editing');
  if (active && event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    cancelEdit(active);
  }
  const form = event.target.closest('[data-ticket-form]');
  if (
    form &&
    event.key === 'Enter' &&
    (event.target.name === 'title' || event.metaKey || event.ctrlKey)
  ) {
    event.preventDefault();
    form.requestSubmit();
  }
  if (event.target.matches('[data-comment-composer] textarea')) mentionKeys(event);
});
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!form.matches('[data-ticket-form]')) return;
  event.preventDefault();
  if (form.hasAttribute('data-auto-save')) finishEdit(form.closest('[data-inline-field]'));
  else saveTicket(form, event.submitter);
});
// The one write queue for a ticket. A contributed widget submits through its own
// form and lands here as well, so there is no second auto-save, no second fetch
// and no second idea of which version is current.
export function saveTicket(form, submitter = null) {
  const workspace = form.closest('.ticket-workspace');
  if (!workspace || !form.isConnected || workspace.dataset.needsReload)
    return Promise.resolve(false);
  if (pendingForms.has(form)) return pendingForms.get(form);
  const previous = saving.get(workspace) || Promise.resolve();
  const task = previous
    .then(() => {
      if (!form.isConnected || workspace.dataset.needsReload) return false;
      return performSave(form, submitter);
    })
    .finally(() => {
      if (saving.get(workspace) === task) saving.delete(workspace);
      pendingForms.delete(form);
    });
  saving.set(workspace, task);
  pendingForms.set(form, task);
  return task;
}
async function performSave(form, submitter) {
  const workspace = form.closest('.ticket-workspace');
  const automatic = form.hasAttribute('data-auto-save');
  const submittedValues = automatic ? values(form) : null;
  const editor = editors.get(form);
  if (editor) form.elements.description_html.value = editor.getSemanticHTML();
  else if (form.querySelector('[data-rich-editor]') && !form.hasAttribute('data-ticket-create'))
    return error(form, 'Der Texteditor ist noch nicht bereit.');
  const data = new FormData(form);
  if (submitter?.name) data.append(submitter.name, submitter.value);
  const controls = (
    automatic ? [] : [...form.querySelectorAll('button,input,select,textarea')]
  ).filter((control) => !control.disabled);
  controls.forEach((control) => {
    control.disabled = true;
  });
  if (!automatic) editor?.enable(false);
  inlineStatus(form, 'Wird gespeichert …');
  const state = workspace.querySelector('.ticket-save-state');
  state.textContent = 'Wird gespeichert …';
  form.querySelector('.form-errors')?.classList.remove('visible');
  let accepted = false;
  const field = form.closest('[data-inline-field]');
  const fieldName = field?.dataset.inlineField;
  try {
    const response = await fetch(form.getAttribute('action'), {
      method: 'POST',
      body: data,
      headers: { Accept: 'application/json' },
    });
    const result = await response
      .json()
      .catch(() => ({ message: 'Bitte melde dich erneut an oder lade die Seite neu.' }));
    if (!response.ok || response.redirected || typeof result.url !== 'string') {
      error(
        form,
        [
          result.message || 'Speichern fehlgeschlagen.',
          ...Object.values(result.errors || {}).flat(),
        ].join(' '),
        response.status === 409,
      );
      state.textContent = 'Nicht gespeichert';
      inlineStatus(form, 'Nicht gespeichert');
      if (automatic && response.status === 409) form.dataset.conflict = 'true';
      return false;
    }
    accepted = true;
    if (form.hasAttribute('data-ticket-create')) {
      workspace.dataset.ticketUrl = result.url;
      delete form.dataset.dirty;
      history.replaceState(history.state, '', result.url);
      document.dispatchEvent(new CustomEvent('nafinity:ai-changed'));
      const response = await fetch(`${result.url}?fragment=1`, {
        headers: { Accept: 'text/html' },
      });
      if (!response.ok || response.redirected)
        throw new Error('Ticket erstellt. Bitte öffne den gespeicherten Stand.');
      const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
      const fresh = parsed.querySelector('.ticket-workspace:not(.ticket-creating)');
      if (!fresh) throw new Error('Ticket erstellt. Bitte öffne den gespeicherten Stand.');
      if (!workspace.classList.contains('in-drawer')) {
        // A direct visit keeps the normal page layout and progressive-enhancement fallback.
        location.assign(result.url);
        return;
      }
      closeChoices(workspace);
      workspace.replaceWith(fresh);
      enhanceChoices(fresh);
      document.querySelector('#ticket-drawer')?.setAttribute('aria-label', 'Ticketdetails');
      fresh.querySelector('.ticket-save-state').textContent = 'Ticket erstellt';
      fresh.querySelector('.ticket-title .inline-display')?.focus();
      toast('Ticket erstellt');
      return;
    }
    // A ticket that has moved away no longer answers at the address this page was read
    // from, so there is nothing here to refresh: the whole page follows it.
    if (form.hasAttribute('data-leaves-page')) {
      location.assign(result.url);
      return true;
    }
    await refresh(workspace, field, form.dataset.refreshSection);
    if (automatic) {
      inlineState(form).baseline = submittedValues;
      changed(form);
      inlineStatus(
        form,
        form.dataset.dirty === 'true' ? 'Weitere Änderungen ausstehend …' : 'Gespeichert',
      );
    } else delete form.dataset.dirty;
    if (form.hasAttribute('data-comment-composer')) {
      form.reset();
      form.querySelector('.reply-context').hidden = true;
      form.querySelector('.mention-list').hidden = true;
      form.elements.body.setAttribute('aria-expanded', 'false');
      delete form.dataset.replyHandle;
      form.elements.body.focus();
    }
    if (fieldName && !automatic)
      workspace.querySelector(`[data-inline-field="${fieldName}"] .inline-display`)?.focus();
    state.textContent =
      automatic && form.dataset.dirty === 'true'
        ? 'Weitere Änderungen ausstehend …'
        : 'Gespeichert';
    return true;
  } catch (exception) {
    if (accepted) {
      workspace.dataset.needsReload = 'true';
      // Changes typed during the accepted request remain an unsaved draft.
      if (automatic) {
        inlineState(form).baseline = submittedValues;
        changed(form);
      } else delete form.dataset.dirty;
      inlineStatus(form, 'Gespeichert · Bitte neu laden');
      state.textContent = 'Gespeichert · Bitte neu laden';
      error(form, exception.message, true);
    } else {
      state.textContent = 'Nicht gespeichert';
      inlineStatus(form, 'Nicht gespeichert');
      error(form, 'Die Verbindung ist unterbrochen. Deine Eingaben bleiben erhalten.');
    }
    return false;
  } finally {
    controls.forEach((control) => {
      control.disabled = false;
    });
    if (!automatic) editor?.enable(true);
    if (
      automatic &&
      accepted &&
      form.isConnected &&
      !workspace.dataset.needsReload &&
      changed(form)
    )
      scheduleSave(form);
    if (form.isConnected && form.hasAttribute('data-ticket-create') && !editors.has(form))
      initializeEditor(form).catch((exception) => error(form, exception.message));
    if (accepted && !workspace.dataset.needsReload && form.hasAttribute('data-comment-composer'))
      form.elements.body.focus();
  }
}
function mentionOptions(textarea) {
  const form = textarea.form;
  const list = form.querySelector('.mention-list');
  const before = textarea.value.slice(0, textarea.selectionStart);
  const match = before.match(/(?:^|\s)@([\p{L}\p{N}._-]*)$/u);
  list.replaceChildren();
  list.hidden = true;
  textarea.setAttribute('aria-expanded', 'false');
  textarea.removeAttribute('aria-activedescendant');
  if (!match) return;
  const search = match[1].toLocaleLowerCase();
  const members = [...form.querySelector('[data-mention-members]').content.querySelectorAll('span')]
    .filter((member) =>
      `${member.dataset.handle} ${member.dataset.name}`.toLocaleLowerCase().includes(search),
    )
    .slice(0, 8);
  for (const [index, member] of members.entries()) {
    const option = document.createElement('button');
    option.type = 'button';
    option.setAttribute('role', 'option');
    option.setAttribute('aria-selected', String(index === 0));
    option.id = `mention-option-${index}`;
    option.textContent = `${member.dataset.name} · @${member.dataset.handle}`;
    option.addEventListener('mousedown', (event) => event.preventDefault());
    option.addEventListener('click', () => {
      textarea.setRangeText(
        `@${member.dataset.handle} `,
        textarea.selectionStart - match[1].length - 1,
        textarea.selectionStart,
        'end',
      );
      list.hidden = true;
      textarea.setAttribute('aria-expanded', 'false');
      textarea.removeAttribute('aria-activedescendant');
      form.dataset.dirty = 'true';
      textarea.focus();
    });
    list.append(option);
  }
  list.hidden = !members.length;
  textarea.setAttribute('aria-expanded', String(members.length > 0));
  if (members.length) textarea.setAttribute('aria-activedescendant', 'mention-option-0');
}
function mentionKeys(event) {
  const textarea = event.target;
  const list = textarea.form.querySelector('.mention-list');
  if (list.hidden) return;
  const options = [...list.querySelectorAll('button')];
  const active = options.findIndex((option) => option.getAttribute('aria-selected') === 'true');
  if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
    event.preventDefault();
    const index = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
    options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
    textarea.setAttribute('aria-activedescendant', options[index].id);
  } else if (['Enter', 'Tab'].includes(event.key) && !event.metaKey && !event.ctrlKey) {
    event.preventDefault();
    options[active]?.click();
  } else if (event.key === 'Escape') {
    event.preventDefault();
    event.stopPropagation();
    list.hidden = true;
    textarea.setAttribute('aria-expanded', 'false');
  }
}
function dirty(scope = document) {
  return !!scope.querySelector('[data-ticket-form][data-dirty=true]');
}
window.addEventListener('beforeunload', (event) => {
  if (dirty()) {
    event.preventDefault();
    event.returnValue = '';
  }
});
function confirmClose(drawer) {
  const workspace = drawer.querySelector('.ticket-workspace');
  const inline = workspace?.querySelector('.is-editing [data-auto-save]');
  const otherDraft = workspace?.querySelector(
    '[data-ticket-form]:not([data-auto-save])[data-dirty=true]',
  );
  if (
    inline &&
    !otherDraft &&
    !workspace.dataset.needsReload &&
    (inline.dataset.dirty === 'true' || saving.has(workspace))
  ) {
    flushInline(inline).then((saved) => {
      if (saved && !dirty(drawer)) drawer.close();
      else if (drawer.open && dirty(drawer)) showDiscard();
    });
    return false;
  }
  if (workspace && saving.has(workspace)) {
    toast('Bitte warte, bis das Speichern abgeschlossen ist.');
    return false;
  }
  if (!dirty(drawer)) return true;
  showDiscard();
  return false;
}
function showDiscard() {
  const confirmation = document.querySelector('#ticket-discard');
  if (confirmation && !confirmation.open) {
    confirmation.returnValue = 'keep';
    confirmation.showModal();
  }
}
const ticketDrawer = document.querySelector('#ticket-drawer');
ticketDrawer?.addEventListener('nafinity:ticket-before-close', (event) => {
  if (!confirmClose(ticketDrawer)) event.preventDefault();
});
ticketDrawer?.addEventListener('cancel', (event) => {
  if (!confirmClose(ticketDrawer)) event.preventDefault();
});
document.querySelector('#ticket-discard')?.addEventListener('close', (event) => {
  if (event.currentTarget.returnValue === 'discard') ticketDrawer?.close();
});
