(function () {
	const qs = new URLSearchParams(location.search);
	const initial = qs.get('category') || 'all';

	const els = {
		sidebar: document.querySelector('aside[aria-label="Categories"]'),
		catList: document.getElementById('catList'),
		products: document.getElementById('products'),
		list: document.getElementById('productsList'),
		loading: document.getElementById('productsLoading'),
		empty: document.getElementById('productsEmpty'),
		tplCard: document.getElementById('tpl-product-card'),
		tplCat: document.getElementById('tpl-cat-item'),
		filterInput: document.getElementById('filterInput'),
		addBtn: document.getElementById('addFilterBtn'),
		addModal: document.getElementById('addModal'),
		addSaveBtn: document.getElementById('addSaveBtn'),
	};

	if (els.sidebar && !els.sidebar.id) els.sidebar.id = 'side-cats';

	// --- pagination state ---
	const state = {
		category: initial,   // slug or 'all'
		page: 1,
		limit: 10,
		loading: false,
		done: false,         // no more pages
		observer: null,
		sentinel: null,
		pending: false,      // <- prevents stacking during scroll delay
		scrollDelay: 600,    // <- ms delay ONLY for scroll-triggered loads
		items: [],        // cache of loaded products
		query: '',        // current search
		pending: false,   // you already added for scroll delay
		term: '',      // current search term
	};

	// els additions
	els.pickFilesBtn = document.getElementById('pickFilesBtn');
	els.fileInput = document.getElementById('fileInput');
	els.dropZone = document.getElementById('dropZone');
	els.previewOne = document.getElementById('previewOne');
	els.previewImg = els.previewOne?.querySelector('.preview__img');
	els.previewName = els.previewOne?.querySelector('.preview__name');
	els.previewBar = els.previewOne?.querySelector('.preview__prog > div');
	els.progress = document.getElementById('overallProgress');
	els.progressBar = els.progress?.querySelector('.progress__bar');
	els.progressLbl = els.progress?.querySelector('.progress__label');

	// state additions
	state.file = null;
	state.saving = false;

	document.addEventListener('DOMContentLoaded', init);

	async function init() {
		// Build categories
		const cats = await fetchCategories();
		renderCategories(cats, state.category);
		populateCategorySelect(cats);
		ensureSentinel(); // for infinite scroll

		// Search
		if (els.filterInput) {
			const onType = debounce((e) => {
				state.query = (e.target.value || '').trim();
				resetAndLoad();                     // restart pagination with q
			}, 300);
			els.filterInput.addEventListener('input', onType);
		}

		// Modal open/close + save
		els.addBtn?.addEventListener('click', openAddModal);
		els.addModal?.addEventListener('click', (e) => {
			if (e.target.matches('[data-close], .modal__backdrop')) closeAddModal();
		});
		els.addSaveBtn?.addEventListener('click', handleAddSave);

		// Uploader
		els.pickFilesBtn?.addEventListener('click', () => els.fileInput?.click());
		els.fileInput?.addEventListener('change', (e) => setSingleFile(e.target.files && e.target.files[0]));
		// Drag & drop
		['dragenter', 'dragover'].forEach(ev =>
			els.dropZone?.addEventListener(ev, (e) => {
				e.preventDefault(); e.stopPropagation();
				els.dropZone.classList.add('dropzone--over');
			})
		);
		['dragleave', 'drop'].forEach(ev =>
			els.dropZone?.addEventListener(ev, (e) => {
				e.preventDefault(); e.stopPropagation();
				els.dropZone.classList.remove('dropzone--over');
			})
		);
		els.dropZone?.addEventListener('drop', (e) => {
			const f = e.dataTransfer?.files && e.dataTransfer.files[0];
			if (f) setSingleFile(f);
		});

		// Initial load (no delay)
		await resetAndLoad();

		// Category click → restart pagination (keeps current query)
		els.catList?.addEventListener('click', onCategoryClick);
	}

	// ---------------- data ----------------
	async function fetchCategories() {
		try {
			const res = await window.Http.get('/api/categories');
			const list = Array.isArray(res?.result) ? res.result : [];
			const normalized = list.map(c => ({
				slug: (c.slug || slugify(c.name || '')).toLowerCase(),
				name: c.name || c.slug || ''
			})).filter(c => c.slug && c.name);
			return [{ slug: 'all', name: 'All' }, ...dedupeBySlug(normalized)];
		} catch {
			console.warn('[top.js] /api/categories failed — using All only');
			return [{ slug: 'all', name: 'All' }];
		}
	}

	// ---------------- pagination core ----------------
	async function resetAndLoad() {
		clearProducts();
		state.items = [];        // reset cache
		state.page = 1;
		state.done = false;
		state.pending = false;

		await loadNextPage();    // no delay
		attachObserver();
	}

	async function loadNextPage() {
		if (state.loading || state.done) return;
		state.loading = true;
		showLoading(true);

		try {
			const params = { limit: state.limit, page: state.page };
			if (state.category && state.category !== 'all') params.category = state.category;
			if (state.query) params.q = state.query;          // ← include search term

			const res = await window.Http.get('/api/products', { params });
			const rows = Array.isArray(res?.result) ? res.result : [];

			if (state.page === 1 && rows.length === 0) {
				clearProducts();
				showEmpty(true, state.query ? `No results for “${state.query}”.` : 'No products found.');
			} else {
				for (const p of rows) appendProduct(p);
				showEmpty(false);
			}

			if (rows.length < state.limit) {
				state.done = true;
				detachObserver();
			} else {
				state.page += 1;
			}
		} catch (e) {
			console.error('[top.js] loadNextPage failed', e);
			state.done = true;
			detachObserver();
			if (state.page === 1) showEmpty(true, 'Failed to load products');
		} finally {
			state.loading = false;
			showLoading(false);
		}
	}

	function ensureSentinel() {
		if (state.sentinel) return;
		const s = document.createElement('div');
		s.setAttribute('aria-hidden', 'true');
		s.style.height = '1px';
		s.style.width = '100%';
		s.style.opacity = '0';
		// append sentinel *after* the products list
		els.products.appendChild(s);
		state.sentinel = s;
	}

	function attachObserver() {
		if (!state.sentinel) return;
		if (state.observer) state.observer.disconnect();
		state.observer = new IntersectionObserver((entries) => {
			for (const entry of entries) {
				if (!entry.isIntersecting) continue;
				if (state.loading || state.done || state.pending) continue;

				// delay ONLY for scroll-triggered loads
				state.pending = true;
				(async () => {
					await sleep(state.scrollDelay);
					state.pending = false;
					if (!state.loading && !state.done) {
						await loadNextPage();
					}
				})();
			}
		}, { root: null, rootMargin: '300px 0px', threshold: 0 });
		state.observer.observe(state.sentinel);
	}

	function detachObserver() {
		if (state.observer) {
			state.observer.disconnect();
			state.observer = null;
		}
	}

	// ---------------- render (no innerHTML for lists) ----------------
	function renderCategories(cats, current) {
		// keep initial "All", refresh rest
		while (els.catList.children.length > 1) {
			els.catList.removeChild(els.catList.lastElementChild);
		}
		const allLink = els.catList.querySelector('.cat-link[data-cat="all"]');
		if (allLink) {
			const is = current === 'all';
			allLink.classList.toggle('active', is);
			if (is) allLink.setAttribute('aria-current', 'true');
			else allLink.removeAttribute('aria-current');
		}
		for (const c of cats) {
			if (c.slug === 'all') continue;
			const frag = els.tplCat.content.cloneNode(true);
			const a = frag.querySelector('.cat-link');
			a.textContent = c.name;
			a.dataset.cat = c.slug;
			if (c.slug === current) {
				a.classList.add('active');
				a.setAttribute('aria-current', 'true');
			}
			els.catList.appendChild(frag);
		}
	}

	function clearProducts() {
		while (els.list.firstChild) els.list.removeChild(els.list.firstChild);
		showEmpty(false);
	}

	function appendProduct(p) {
		const node = els.tplCard.content.cloneNode(true);
		node.querySelector('.p-img').setAttribute('src', p.image_url || '');
		node.querySelector('.p-img').setAttribute('alt', p.name || '');
		node.querySelector('.p-name').textContent = p.name || '';
		node.querySelector('.p-desc').textContent = p.description || '';
		node.querySelector('.p-price').textContent = `¥${Number(p.price ?? 0).toLocaleString()}`;
		node.querySelector('.p-stock').textContent = ` ・ stock: ${p.stock_qty ?? 0}`;
		els.list.appendChild(node);
	}

	// ---------------- interactions ----------------
	function onCategoryClick(e) {
		const a = e.target.closest('.cat-link');
		if (!a) return;
		e.preventDefault();

		const slug = a.dataset.cat || 'all';

		// toggle active
		els.catList.querySelectorAll('.cat-link').forEach(x => {
			const is = (x.dataset.cat || 'all') === slug;
			x.classList.toggle('active', is);
			if (is) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current');
		});

		// persist in URL
		const u = new URL(location.href);
		if (slug && slug !== 'all') u.searchParams.set('category', slug);
		else u.searchParams.delete('category');
		history.replaceState(null, '', u.toString());

		// update state and reload from page 1 (no delay)
		state.category = slug;
		resetAndLoad();
	}

	function setSingleFile(file) {
		if (!file) return;
		const accept = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
		if (!accept.includes(file.type)) { window.showToast('Please choose an image.'); return; }

		// Replace previous file (revoke old preview URL if any)
		if (state.file?.objectUrl) URL.revokeObjectURL(state.file.objectUrl);

		const objectUrl = URL.createObjectURL(file);
		state.file = { file, progress: 0, url: null, objectUrl };

		// Update single preview
		if (els.previewImg) els.previewImg.src = objectUrl;
		if (els.previewName) els.previewName.textContent = file.name;
		if (els.previewBar) els.previewBar.style.width = '0%';
		if (els.previewOne) els.previewOne.hidden = false;

		// Reset overall progress
		setOverallProgress(0);
	}

	function setOverallProgress(pct) {
		if (!els.progress || !els.progressBar || !els.progressLbl) return;
		els.progress.hidden = false;
		els.progressBar.style.width = `${pct}%`;
		els.progressLbl.textContent = `${Math.round(pct)}%`;
	}

	function updateFileProgress(pct) {
		if (els.previewBar) els.previewBar.style.width = `${Math.max(0, Math.min(100, pct))}%`;
		setOverallProgress(pct);
	}

	function uploadSingleFile() {
		return new Promise((resolve, reject) => {
			if (!state.file?.file) { resolve(null); return; }

			const xhr = new XMLHttpRequest();
			state.file.xhr = xhr;

			xhr.upload.onprogress = (e) => {
				if (!e.lengthComputable) return;
				const pct = (e.loaded / e.total) * 100;
				state.file.progress = pct;
				updateFileProgress(pct);
			};

			xhr.onload = () => {
				try {
					const json = JSON.parse(xhr.responseText || '{}');
					if (xhr.status >= 200 && xhr.status < 300 && json.url) {
						state.file.url = json.url;
						updateFileProgress(100);
						resolve(json.url);
					} else {
						reject(new Error(json.error || 'upload_failed'));
					}
				} catch (e) { reject(e); }
			};

			xhr.onerror = () => reject(new Error('network_error'));

			const fd = new FormData();
			fd.append('file', state.file.file, state.file.file.name);
			xhr.open('POST', '/api/products'); // your backend uploader
			xhr.send(fd);
		});
	}

	function showLoading(on) { if (els.loading) els.loading.hidden = !on; }
	function showEmpty(on, msg) { if (!els.empty) return; els.empty.hidden = !on; if (on && msg) els.empty.textContent = msg; }

	function renderFromState() {
		// If there’s a query, filter; else render all loaded so far
		const q = state.query;
		const rows = q
			? state.items.filter(p => (p?.name || '').toLowerCase().includes(q))
			: state.items;

		// re-render (no innerHTML): clear then append templates
		while (els.list.firstChild) els.list.removeChild(els.list.firstChild);
		for (const p of rows) appendProduct(p);
	}

	function applyFilter() {
		// When filtering, we don’t fetch more; just re-render current cache
		renderFromState();

		// Optional UX: if filtering, pause infinite scroll; resume when cleared
		if (state.query) {
			detachObserver();
		} else if (!state.done) {
			attachObserver();
		}

		// Empty state message
		if (els.empty) els.empty.hidden = (els.list.children.length !== 0);
	}

	// ---------------- utils ----------------
	function slugify(s) { return String(s || '').toLowerCase().trim().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '') }
	function dedupeBySlug(arr) { const seen = new Set(), out = []; for (const x of arr) { if (!seen.has(x.slug)) { seen.add(x.slug); out.push(x) } } return out }
	function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)) }
	function debounce(fn, ms = 250) { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); } }
	function openAddModal() { if (els.addModal) els.addModal.hidden = false; }
	function closeAddModal() { if (els.addModal) els.addModal.hidden = true; }

	async function handleAddSave() {
		if (state.saving) return;

		const name = document.getElementById('addName')?.value.trim();
		const price = Number(document.getElementById('addPrice')?.value || 0);
		const desc = document.getElementById('addDesc')?.value.trim();
		const cat   = document.getElementById('addCategory')?.value || ''; // slug from dropdown
		const stock = 0; // or read from a field

		if (!name) return showToast('Name is required');
		if (!(price > 0)) return showToast('Price must be greater than 0');
		// if (!cat) return showToast('Choose a category');

		try {
			state.saving = true;
			disableModal(true);

			// Upload image -> returns S3 URL
			let thumbnailUrl = '';
			if (state.file?.file) {
				thumbnailUrl = await uploadSingleFile(); // POST /api/uploads (FormData)
			}

			const addProductParams = {
				name,
				description: desc,
				price,
				stock_qty: stock,
				cagegory: cat,
				thumbnail: thumbnailUrl || ''
			};

			await Http.post('/api/products', addProductParams);

			// 3) Refresh
			closeAddModal();
			clearAddForm();
			await hardRefreshList();
		} catch (e) {
			console.error('save failed', e);
			showToast('Failed to save product.');
		} finally {
			state.saving = false;
			disableModal(false);
		}
	}

	function disableModal(disabled) {
		els.addSaveBtn && (els.addSaveBtn.disabled = disabled);
		els.pickFilesBtn && (els.pickFilesBtn.disabled = disabled);
		if (els.fileInput) els.fileInput.disabled = disabled;
	}

	function clearAddForm() {
		if (state.file?.objectUrl) URL.revokeObjectURL(state.file.objectUrl);
		state.file = null;

		if (els.previewOne) els.previewOne.hidden = true;
		if (els.previewImg) els.previewImg.src = '';
		if (els.previewName) els.previewName.textContent = '';
		if (els.previewBar) els.previewBar.style.width = '0%';
		if (els.progress) { els.progress.hidden = true; setOverallProgress(0); }

		const ids = ['addName', 'addPrice', 'addDesc', 'addCategory'];
		ids.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
	}

	function populateCategorySelect(cats) {
		const sel = document.getElementById('addCategory');
		if (!sel) return;
		while (sel.options.length > 1) sel.remove(1);

		cats.forEach(c => {
			if (c.slug === 'all') return;
			const opt = document.createElement('option');
			opt.value = c.slug;        // send slug to backend
			opt.textContent = c.name;
			sel.appendChild(opt);
		});
	}
})();