// Disposable derived data only: fingerprints and vectors, never chat or tool results.
const MAX_ENTRIES = 2000;
const MAX_AGE = 30 * 24 * 60 * 60 * 1000;

export class MemoryVectorCache {
  entries = new Map();

  async getMany(ids) {
    return new Map(
      ids.filter((id) => this.entries.has(id)).map((id) => [id, this.entries.get(id)]),
    );
  }

  async putMany(entries) {
    for (const [id, vector] of entries) {
      this.entries.delete(id);
      this.entries.set(id, vector);
    }
    while (this.entries.size > MAX_ENTRIES) this.entries.delete(this.entries.keys().next().value);
  }
}

export class BrowserVectorCache extends MemoryVectorCache {
  database;

  async open() {
    if (!this.database) {
      this.database = new Promise((resolve) => {
        if (!globalThis.indexedDB) return resolve(null);
        let settled = false;
        const finish = (database) => {
          if (settled) return database?.close();
          settled = true;
          clearTimeout(timeout);
          resolve(database);
        };
        const timeout = setTimeout(() => finish(null), 2000);
        try {
          const request = indexedDB.open('nafinity-ai-tool-index', 1);
          request.onupgradeneeded = () => {
            const store = request.result.createObjectStore('vectors', { keyPath: 'id' });
            store.createIndex('created', 'created');
          };
          request.onsuccess = () => {
            request.result.onversionchange = () => request.result.close();
            finish(request.result);
          };
          request.onerror = request.onblocked = () => finish(null);
        } catch {
          finish(null);
        }
      });
    }
    return this.database;
  }

  async getMany(ids) {
    const values = await super.getMany(ids);
    const database = await this.open();
    if (!database) return values;
    await new Promise((resolve) => {
      try {
        const transaction = database.transaction('vectors', 'readonly');
        transaction.oncomplete = transaction.onerror = transaction.onabort = resolve;
        const store = transaction.objectStore('vectors');
        for (const id of ids.filter((id) => !values.has(id))) {
          const request = store.get(id);
          request.onsuccess = () => {
            const entry = request.result;
            if (entry && entry.created > Date.now() - MAX_AGE) values.set(id, entry.vector);
          };
        }
      } catch {
        resolve();
      }
    });
    await super.putMany(values);
    return values;
  }

  async putMany(entries) {
    await super.putMany(entries);
    const database = await this.open();
    if (!database) return;
    await new Promise((resolve) => {
      try {
        const transaction = database.transaction('vectors', 'readwrite');
        transaction.oncomplete = transaction.onerror = transaction.onabort = resolve;
        const store = transaction.objectStore('vectors');
        const created = Date.now();
        for (const [id, vector] of entries) store.put({ id, vector, created });
        const count = store.count();
        count.onsuccess = () => {
          let excess = count.result - MAX_ENTRIES;
          const cursor = store.index('created').openCursor();
          cursor.onsuccess = () => {
            const entry = cursor.result;
            if (!entry || (excess <= 0 && entry.key >= created - MAX_AGE)) return;
            entry.delete();
            excess -= 1;
            entry.continue();
          };
        };
      } catch {
        resolve();
      }
    });
  }
}
