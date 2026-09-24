// Applies the stored sidebar state before the first paint, so a pinned panel never
// flashes as a narrow rail. Loaded as a blocking classic script because the
// Content-Security-Policy allows no inline code.
try {
  const stored = localStorage.getItem('nafinity.sidebar');
  if (stored === 'pinned' || stored === 'rail') document.documentElement.dataset.sidebar = stored;
  // The login has no signed-in preference. Use the last choice on this device
  // before CSS paints; signed-in pages take their values from the server.
  if (document.documentElement.dataset.appearanceSource === 'local') {
    const theme = localStorage.getItem('nafinity.theme');
    const palette = localStorage.getItem('nafinity.palette');
    if (['system', 'light', 'dark'].includes(theme)) document.documentElement.dataset.theme = theme;
    if (['classic', 'anthracite'].includes(palette))
      document.documentElement.dataset.palette = palette;
  }
} catch {}
