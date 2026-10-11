// Cancel and invalidate together: a late answer must not repopulate a cleared or closed popup.
export function createSearchLoader(
  endpoint,
  update,
  { fetcher = fetch, delay = 180, minLength = 2 } = {},
) {
  let timer;
  let request;
  let generation = 0;
  function cancel() {
    clearTimeout(timer);
    request?.abort();
    generation++;
  }
  return {
    cancel,
    search(query) {
      cancel();
      query = query.trim();
      if ([...query].length < minLength) {
        update({ state: 'short' });
        return;
      }
      const current = generation;
      update({ state: 'loading' });
      timer = setTimeout(async () => {
        request = new AbortController();
        const url = new URL(endpoint);
        url.searchParams.set('q', query);
        try {
          const response = await fetcher(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: request.signal,
          });
          if (!response.ok || response.redirected) throw new Error('Search failed');
          const data = await response.json();
          if (current === generation) update({ state: 'ready', ...data });
        } catch (error) {
          if (current === generation && error.name !== 'AbortError') update({ state: 'error' });
        }
      }, delay);
    },
  };
}
