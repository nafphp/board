// Applies the stored sidebar state before the first paint, so a pinned panel never
// flashes as a narrow rail. Loaded as a blocking classic script because the
// Content-Security-Policy allows no inline code.
try {
  const stored = localStorage.getItem('nafinity.sidebar');
  if (stored === 'pinned' || stored === 'rail') document.documentElement.dataset.sidebar = stored;
} catch {}
