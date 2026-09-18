import { fetchWorkspace, syncWidgets } from './fragment.js';

// Attaching a file, with the progress the browser's own control never shows. The form below
// stays a plain multipart form: without this script the native field and button still work,
// which is why the file input is only visually hidden and never replaced.
//
// XMLHttpRequest rather than fetch, because only it reports how much of the body has gone
// out; a progress bar that jumps from nothing to done would be a decoration, not a report.

const LIMIT = 10 * 1024 * 1024;

function size(bytes) {
  const mib = bytes / 1024 / 1024;
  if (mib >= 1) return mib.toFixed(1).replace('.', ',') + ' MiB';

  return Math.max(1, Math.round(bytes / 1024)) + ' KiB';
}

function chosen(form) {
  return form.querySelector('[data-upload-input]')?.files?.[0] ?? null;
}

function show(form) {
  const file = chosen(form);
  const box = form.querySelector('[data-upload-chosen]');
  const submit = form.querySelector('[data-upload-submit]');
  form.querySelector('[data-dropzone]').hidden = Boolean(file);
  box.hidden = !file;
  if (submit) submit.disabled = !file;
  if (!file) return;
  form.querySelector('[data-upload-name]').textContent = file.name;
  form.querySelector('[data-upload-size]').textContent = size(file.size);
}

function fail(form, message) {
  const errors = form.querySelector('.form-errors');
  if (errors) errors.textContent = message;
  form.querySelector('[data-upload-progress]').hidden = true;
  delete form.dataset.sending;
  const submit = form.querySelector('[data-upload-submit]');
  if (submit) submit.disabled = !chosen(form);
}

function percent(form, share) {
  const done = Math.max(0, Math.min(1, share));
  form.querySelector('[data-upload-fill]').style.transform = `scaleX(${done})`;
  form.querySelector('[data-upload-percent]').textContent = Math.round(done * 100) + ' %';
}

// The list is rendered by the server, so the fresh one is fetched rather than
// guessed at — through the same fragment path the inline editor uses, so a
// widget that appeared or disappeared meanwhile is handled here as well.
async function relist(form) {
  const workspace = form.closest('[data-ticket-url]');
  const section = form.closest('[data-ticket-section="attachments"]');
  if (!workspace || !section) {
    location.reload();

    return;
  }
  const fresh = await fetchWorkspace(workspace);
  if (!fresh || !fresh.querySelector('[data-ticket-section="attachments"]')) {
    location.reload();

    return;
  }
  syncWidgets(workspace, fresh);
  workspace
    .querySelector('[data-ticket-section="attachments"] .attachment-row:last-of-type')
    ?.classList.add('just-added');
}

function upload(form) {
  const file = chosen(form);
  if (!file || form.dataset.sending) return;
  const errors = form.querySelector('.form-errors');
  if (errors) errors.textContent = '';
  // Refused here as well as on the server, so a long upload is not spent to be rejected.
  if (file.size > LIMIT) {
    fail(form, `Die Datei ist ${size(file.size)} groß. Erlaubt sind 10 MiB.`);

    return;
  }
  form.dataset.sending = '1';
  form.querySelector('[data-upload-submit]').disabled = true;
  form.querySelector('[data-upload-progress]').hidden = false;
  percent(form, 0);

  const request = new XMLHttpRequest();
  form.sending = request;
  request.open('POST', form.action);
  request.setRequestHeader('Accept', 'application/json');
  request.upload.addEventListener('progress', (event) => {
    if (event.lengthComputable) percent(form, event.loaded / event.total);
  });
  request.addEventListener('load', async () => {
    let payload = {};
    try {
      payload = JSON.parse(request.responseText);
    } catch {
      payload = {};
    }
    if (request.status < 200 || request.status >= 300) {
      fail(form, payload.message || 'Die Datei konnte nicht angehängt werden.');

      return;
    }
    // The bar reaches its end before the list changes, so the eye follows one thing at a time.
    percent(form, 1);
    setTimeout(() => relist(form), 220);
  });
  request.addEventListener('error', () =>
    fail(form, 'Die Verbindung wurde unterbrochen. Bitte versuche es erneut.'),
  );
  request.addEventListener('abort', () => {
    form.querySelector('[data-upload-progress]').hidden = true;
    delete form.dataset.sending;
    show(form);
  });
  request.send(new FormData(form));
}

function wire(form) {
  if (form.dataset.wired) return;
  form.dataset.wired = '1';
  // Marks that a script is here, which is what hides the submit button: choosing a file is
  // the whole action now, and the button stays for the case where none of this runs.
  form.dataset.auto = '1';
  show(form);
}

for (const form of document.querySelectorAll('[data-upload]')) wire(form);

document.addEventListener('change', (event) => {
  const form = event.target.closest?.('[data-upload]');
  if (!form || !event.target.matches('[data-upload-input]')) return;
  show(form);
  // Choosing is the whole action; there is nothing further to confirm.
  if (chosen(form)) upload(form);
});

document.addEventListener('click', (event) => {
  const form = event.target.closest?.('[data-upload]');
  if (!form || !event.target.closest('[data-upload-clear]')) return;
  // Mid-flight the same control calls the upload off rather than just forgetting the file.
  form.sending?.abort();
  form.sending = null;
  form.querySelector('[data-upload-input]').value = '';
  const errors = form.querySelector('.form-errors');
  if (errors) errors.textContent = '';
  show(form);
});

document.addEventListener('submit', (event) => {
  const form = event.target.closest?.('[data-upload]');
  if (!form) return;
  event.preventDefault();
  upload(form);
});

// Dropping a file fills the very same input, so everything downstream stays one code path.
for (const type of ['dragenter', 'dragover']) {
  document.addEventListener(type, (event) => {
    const zone = event.target.closest?.('[data-dropzone]');
    if (!zone) return;
    event.preventDefault();
    zone.dataset.over = '1';
  });
}
for (const type of ['dragleave', 'drop']) {
  document.addEventListener(type, (event) => {
    const zone = event.target.closest?.('[data-dropzone]');
    if (zone) delete zone.dataset.over;
  });
}
document.addEventListener('drop', (event) => {
  const zone = event.target.closest?.('[data-dropzone]');
  const form = zone?.closest('[data-upload]');
  const file = event.dataTransfer?.files?.[0];
  if (!form || !file) return;
  event.preventDefault();
  const transfer = new DataTransfer();
  transfer.items.add(file);
  form.querySelector('[data-upload-input]').files = transfer.files;
  show(form);
  upload(form);
});

// A ticket opened in the panel brings its own form with it.
document.addEventListener('nafinity:ticket-opened', () => {
  for (const form of document.querySelectorAll('[data-upload]')) wire(form);
});
