// Server-rendered srcdoc previews stay script-free and outside the parent's CSS.
// Share a single outer scroll container, even after moving a preview into a modal.
export function mountIsolatedPreview(frame) {
  function resize() {
    const doc = frame.contentDocument;
    if (!doc?.body) return;
    doc.documentElement.inert = true;
    const height = doc.body.getBoundingClientRect().height;
    if (height > 0) frame.style.height = Math.min(1800, Math.max(360, Math.ceil(height))) + 'px';
  }
  frame.addEventListener('load', resize);
  if (globalThis.ResizeObserver) new ResizeObserver(resize).observe(frame);
  resize();
  return resize;
}
