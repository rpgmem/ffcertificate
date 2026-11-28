/**
 * A minimal IndexedDB for the terminal's sign-up key (#2968): one
 * database, its stores kept in memory, every request answered on the next
 * tick.
 */
export function memoryIndexedDB() {
	const stores = new Map();
	const later = (request, value) => setTimeout(() => { request.result = value; request.onsuccess && request.onsuccess(); }, 0);
	const db = {
		objectStoreNames: { contains: (name) => stores.has(name) },
		createObjectStore: (name) => { stores.set(name, new Map()); },
		transaction: (name) => ({
			objectStore: () => ({
				get: (id) => { const request = {}; later(request, stores.get(name).get(id)); return request; },
				put: (value, id) => { const request = {}; stores.get(name).set(id, value); later(request, id); return request; },
			}),
		}),
		close: () => {},
	};
	return {
		stores,
		open: () => {
			const request = { result: db };
			setTimeout(() => {
				if (!stores.size && request.onupgradeneeded) {
					request.onupgradeneeded();
				}
				request.onsuccess && request.onsuccess();
			}, 0);
			return request;
		},
	};
}

