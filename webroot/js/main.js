// webroot/js/main.js
(function () {
	const THEME_KEY = 'theme';           // 'light' | 'dark'
	const root = document.documentElement;
	const mq = window.matchMedia('(prefers-color-scheme: dark)');

	function readStorage(key) {
		try { return localStorage.getItem(key); } catch { return null; }
	}
	function writeStorage(key, val) {
		try { localStorage.setItem(key, val); } catch { }
	}
	function clearStorage(key) {
		try { localStorage.removeItem(key); } catch { }
	}

	function systemTheme() {
		return mq.matches ? 'dark' : 'light';
	}

	function applyTheme(mode) {
		root.dataset.theme = mode; // switch CSS token
		// Optional: help UA pick colors for built-in widgets
		const meta = document.querySelector('meta[name="color-scheme"]');
		if (meta) meta.setAttribute('content', mode === 'dark' ? 'dark light' : 'light dark');
	}

	function initTheme() {
		const stored = readStorage(THEME_KEY);              // 'light' | 'dark' | null
		if (stored === 'light' || stored === 'dark') {
			applyTheme(stored);
		} else {
			applyTheme(systemTheme());
		}
		// Follow OS changes ONLY when there is NO stored preference
		mq.addEventListener?.('change', (e) => {
			const storedNow = readStorage(THEME_KEY);
			if (storedNow !== 'light' && storedNow !== 'dark') {
				applyTheme(e.matches ? 'dark' : 'light');
			}
		});
	}

	function setTheme(mode) {
		if (mode !== 'light' && mode !== 'dark') return;
		writeStorage(THEME_KEY, mode);
		applyTheme(mode);
	}

	function toggleTheme() {
		const next = (root.dataset.theme === 'dark') ? 'light' : 'dark';
		setTheme(next);
	}

	window.showToast = function(msg, ms = 2200) {
		let node = document.getElementById('toast');
		if (!node) return;
		const body = node.querySelector('.toast__body');
		if (!body) return;
		body.textContent = msg;
		node.hidden = false;
		node.classList.add('toast--show');
		setTimeout(() => {
			node.classList.remove('toast--show');
			setTimeout(() => { node.hidden = true; }, 3000);
		}, ms);
	}

	// --- (rest of your helpers/actions remain the same) ---
	const $ = (sel, rootEl = document) => rootEl.querySelector(sel);
	const $$ = (sel, rootEl = document) => Array.from(rootEl.querySelectorAll(sel));
	function onReady(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

	async function fetchJSON(url, { method = 'GET', body, timeout = 8000, headers } = {}) {
		const ctrl = new AbortController(); const id = setTimeout(() => ctrl.abort(), timeout);
		try {
			const res = await fetch(url, {
				method, headers: { 'Content-Type': 'application/json', ...(headers || {}) },
				body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin', signal: ctrl.signal
			});
			if (!res.ok) throw new Error(`HTTP ${res.status}`);
			return await res.json();
		} finally { clearTimeout(id); }
	}

	const Toast = (() => {
		let node;
		function ensure() { if (!node) { node = document.createElement('div'); node.className = 'toast'; document.body.appendChild(node); } }
		function show(msg, ms = 2200) { ensure(); node.textContent = msg; node.classList.add('toast--show'); setTimeout(() => node.classList.remove('toast--show'), ms); }
		return { show };
	})();

	const Actions = {
		toggleTheme() { toggleTheme(); },
		setLight() { setTheme('light'); },
		setDark() { setTheme('dark'); },
		async loadProducts() {
			try { const { data } = await fetchJSON('/api/products'); console.log('Products:', data); Toast.show(`Loaded ${Array.isArray(data) ? data.length : 0} products`); }
			catch (e) { console.error(e); Toast.show('Failed to load products'); }
		},
		ping() { Toast.show('Pong!'); }
	};

	function onActionClick(e) {
		const el = e.target.closest('[data-action]'); if (!el) return;
		const name = el.getAttribute('data-action'); if (!name || !Actions[name]) return;
		e.preventDefault(); Actions[name](el);
	}

	// Sidebar toggle for mobile (attach to any [data-toggle="sidebar"])
	document.addEventListener('click', (e) => {
		const btn = e.target.closest('[data-toggle="sidebar"]');
		if (!btn) return;
		e.preventDefault();
		const open = !document.body.classList.contains('nav-open');
		document.body.classList.toggle('nav-open', open);
		btn.setAttribute('aria-expanded', String(open));
	});

	// Close sidebar if user clicks the dim backdrop
	document.addEventListener('click', (e) => {
		if (!document.body.classList.contains('nav-open')) return;
		// If clicking outside the sidebar when open
		const withinSidebar = e.target.closest('#side-cats') || e.target.closest('[data-toggle="sidebar"]');
		if (!withinSidebar) document.body.classList.remove('nav-open');
	});

	// HTTP client (GET/POST/PUT/DELETE)
	// webroot/js/main.js
	window.Http = (() => {
		const defaultHeaders = { 'Content-Type': 'application/json' };

		function buildQuery(params) {
			if (!params) return '';
			const sp = new URLSearchParams();
			for (const [k, v] of Object.entries(params)) {
				if (v == null) continue;
				sp.append(k, v);
			}
			const qs = sp.toString();
			return qs ? `?${qs}` : '';
		}

		async function request(method, url, { params, data, headers, timeout = 10000 } = {}) {
			const ctrl = new AbortController();
			const t = setTimeout(() => ctrl.abort(), timeout);
			try {
				const res = await fetch(url + buildQuery(params), {
					method,
					headers: { ...defaultHeaders, ...(headers || {}) },
					body: data != null ? JSON.stringify(data) : undefined,
					credentials: 'same-origin',
					signal: ctrl.signal
				});
				const text = await res.text();
				const isJSON = (res.headers.get('content-type') || '').includes('application/json');
				const payload = isJSON && text ? JSON.parse(text) : (isJSON ? null : text);
				if (!res.ok) {
					const err = new Error(`HTTP ${res.status}`);
					err.status = res.status;
					err.payload = payload;
					throw err;
				}
				return payload;
			} finally { clearTimeout(t); }
		}

		return {
			get: (url, opts) => request('GET', url, opts),
			post: (url, opts) => request('POST', url, opts),
			put: (url, opts) => request('PUT', url, opts),
			patch: (url, opts) => request('PATCH', url, opts),
			delete: (url, opts) => request('DELETE', url, opts),
		};
	})();

	onReady(() => {
		initTheme();
		document.addEventListener('click', onActionClick);
		window.App = { fetchJSON, Toast, $, $$, setTheme, toggleTheme, clearThemePref: () => clearStorage(THEME_KEY) };
	});
})();