//-----------------------------------------------
// Total CMS Collection Table Component (HTMX)
//-----------------------------------------------
export default class AdminTable {

	constructor(wrapper) {
		this.wrapper    = wrapper;
		this.table      = wrapper.querySelector('table.admin-table');
		this.collection = this.table.dataset.collection;
		this.api        = this.table.dataset.api;
		this.limit      = parseInt(this.table.dataset.limit, 10) || 50;
		this.sortField  = this.table.dataset.defaultSort || 'id';
		this.sortDir    = this.table.dataset.defaultSortDir || 'asc';
		this.searchInput = wrapper.querySelector('input[type="search"]');

		// Bulk selection state. Keyed by object id so it survives the HTMX
		// tbody swaps that filtering/sorting/paging trigger — the selection is
		// the source of truth, the checkboxes are re-derived from it.
		this.canDelete       = this.wrapper.dataset.canDelete === '1';
		this.selected        = new Set();
		this.selecting       = false;
		this.showingSelected = false;
		this.lastChecked     = null;
		this.bar             = this.wrapper.querySelector('.bulk-action-bar');
		this.countEl         = this.wrapper.querySelector('.bulk-count');

		this.initSortHeaders();
		this.initDelegatedListeners();
		this.initCloneDialog();
		if (this.canDelete) this.initBulkSelection();
		this.focusSearchInput();
	}

	initSortHeaders() {
		const headers = this.table.querySelectorAll('thead th.sortable');
		headers.forEach(th => {
			th.addEventListener('click', () => this.handleSortClick(th));
		});
	}

	initDelegatedListeners() {
		// Row click navigation (delegated on tbody)
		this.wrapper.addEventListener('click', (e) => {
			const target = e.target;

			// In selection mode a row click toggles its checkbox instead of
			// navigating (clicks on the checkbox/links/buttons fall through).
			if (this.selecting) {
				const selRow = target.closest('tr[data-object-id]');
				if (selRow && !target.closest('a') && !target.closest('button') && !target.closest('nav') && !target.closest('.row-select')) {
					const box = selRow.querySelector('.row-select');
					if (box) {
						box.checked = !box.checked;
						this.onRowToggle(box, e.shiftKey);
					}
					return;
				}
			}

			// Handle row clicks for navigation
			const row = target.closest('tr[data-object-id]');
			if (row && !target.closest('td.action') && !target.closest('button') && !target.closest('a') && !target.closest('nav')) {
				this.handleRowClick(e, row);
				return;
			}

			// Handle clone links
			if (target.closest('.clone > a')) {
				e.preventDefault();
				this.openCloneDialog(target.closest('.clone > a'));
				return;
			}
		});

		// Handle popover positioning
		this.wrapper.addEventListener('pointerdown', (e) => {
			const target = e.target;
			if (target.matches('button[popovertarget]')) {
				this.openPopover(target);
			}
		});

	}

	handleSortClick(th) {
		const field = th.dataset.sortField;
		let dir = th.dataset.sortDir;

		// Toggle: none -> asc -> desc -> asc
		if (dir === 'none' || dir === 'desc') {
			dir = 'asc';
		} else {
			dir = 'desc';
		}

		// Reset all other headers
		this.table.querySelectorAll('thead th.sortable').forEach(header => {
			header.dataset.sortDir = 'none';
		});
		th.dataset.sortDir = dir;

		// Update stored sort
		this.sortField = field;
		this.sortDir = dir;

		// Build URL and fire HTMX request
		this.refreshTable();
	}

	handleRowClick(e, row) {
		const id = row.dataset.objectId;
		if (!id) return;

		const url = `${window.location.href}/${id}`;
		if (e.metaKey || e.ctrlKey) {
			window.open(url, '_blank');
		} else {
			window.location.href = url;
		}
	}

	refreshTable() {
		const params = new URLSearchParams({
			format      : 'table',
			limit       : this.limit.toString(),
			sort        : `${this.sortField}:${this.sortDir}`,
			_collection : this.collection,
			_api        : this.api,
		});

		// "Show selected" overrides the filter to render only the chosen ids.
		if (this.showingSelected) {
			params.set('ids', [...this.selected].join(','));
		} else {
			// Include filter term if active (contains match across all fields)
			const filter = this.searchInput?.value?.trim();
			if (filter) {
				params.set('filter', filter);
			}
		}

		const url    = `${this.api}/collections/${this.collection}/query?${params}`;
		const target = `#table-body-${this.collection}`;

		htmx.ajax('GET', url, { target, swap: 'innerHTML' });

		// Update search input's hx-vals to include current sort
		this.updateSearchSort();
	}

	updateSearchSort() {
		if (!this.searchInput) return;

		const vals = JSON.parse(this.searchInput.getAttribute('hx-vals') || '{}');
		vals.sort = `${this.sortField}:${this.sortDir}`;
		this.searchInput.setAttribute('hx-vals', JSON.stringify(vals));

		// Re-process the element so HTMX picks up the new hx-vals
		htmx.process(this.searchInput);
	}

	//-------------------------------------------------
	// Bulk selection
	//-------------------------------------------------
	initBulkSelection() {
		// Enter selection mode from a row's "Select items" action.
		this.wrapper.addEventListener('click', (e) => {
			const start = e.target.closest('.bulk-select-start');
			if (start) {
				e.preventDefault();
				const row = start.closest('tr[data-object-id]');
				start.closest('[popover]')?.hidePopover?.();
				this.enterSelectionMode();
				if (row) {
					const box = row.querySelector('.row-select');
					if (box) { box.checked = true; this.onRowToggle(box, false); }
				}
				return;
			}

			if (e.target.closest('.bulk-clear'))         { this.clearSelection(); return; }
			if (e.target.closest('.bulk-done'))          { this.exitSelectionMode(); return; }
			if (e.target.closest('.bulk-show-selected')) { this.toggleShowSelected(); return; }
			if (e.target.closest('.bulk-delete'))        { e.preventDefault(); this.runDelete(); return; }
		});

		// Checkbox toggles (capture shift for range selection via click).
		this.wrapper.addEventListener('click', (e) => {
			const box = e.target.closest('.row-select');
			if (box) { this.onRowToggle(box, e.shiftKey); return; }
		});

		// Re-derive checkbox state from the selection after every tbody swap
		// (filter / sort / paging / show-selected all replace the rows). HTMX 4
		// uses colon-delimited event names — `htmx:after:swap`, not the old
		// camelCase `htmx:afterSwap`.
		this.wrapper.addEventListener('htmx:after:swap', (e) => {
			if (e.target?.id === `table-body-${this.collection}`) this.restoreChecks();
		});

		// Esc exits selection mode (same as Done). If a popover is open, let
		// Esc close it first (native light-dismiss) — a second Esc then exits.
		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape' || !this.selecting) return;
			if (this.wrapper.querySelector('[popover]:popover-open')) return;
			this.exitSelectionMode();
		});
	}

	enterSelectionMode() {
		this.selecting = true;
		this.wrapper.classList.add('selecting');
		this.updateBar();
	}

	exitSelectionMode() {
		this.selecting = false;
		this.showingSelected = false;
		this.wrapper.classList.remove('selecting');
		this.selected.clear();
		this.lastChecked = null;
		this.syncShowSelectedButton();
		// Drop the "show selected" view if it was active.
		this.refreshTable();
	}

	clearSelection() {
		this.selected.clear();
		this.lastChecked = null;
		this.table.querySelectorAll('.row-select').forEach(b => { b.checked = false; });
		if (this.showingSelected) {
			this.showingSelected = false;
			this.syncShowSelectedButton();
			this.refreshTable();
		} else {
			this.updateBar();
		}
	}

	onRowToggle(box, shift) {
		const boxes = Array.from(this.table.querySelectorAll('.row-select'));

		if (shift && this.lastChecked !== null) {
			const start = boxes.indexOf(this.lastChecked);
			const end   = boxes.indexOf(box);
			if (start !== -1 && end !== -1) {
				const [lo, hi] = start < end ? [start, end] : [end, start];
				for (let i = lo; i <= hi; i++) {
					boxes[i].checked = box.checked;
					this.setSelected(boxes[i].value, box.checked);
				}
			}
		} else {
			this.setSelected(box.value, box.checked);
		}

		this.lastChecked = box;
		this.updateBar();
	}

	setSelected(id, on) {
		if (!id) return;
		if (on) this.selected.add(id); else this.selected.delete(id);
	}

	restoreChecks() {
		this.table.querySelectorAll('.row-select').forEach(b => {
			b.checked = this.selected.has(b.value);
		});
		this.updateBar();
	}

	updateBar() {
		if (this.countEl) {
			const n = this.selected.size;
			this.countEl.textContent = `${n} selected`;
		}
	}

	toggleShowSelected() {
		if (this.selected.size === 0) return;
		this.showingSelected = !this.showingSelected;
		this.syncShowSelectedButton();
		this.refreshTable();
	}

	syncShowSelectedButton() {
		const btn = this.wrapper.querySelector('.bulk-show-selected');
		if (!btn) return;
		btn.classList.toggle('active', this.showingSelected);
		const label = this.showingSelected ? 'Show all' : 'Show selected';
		btn.title = label;
		btn.setAttribute('aria-label', label);
	}

	// Drive the shared form status banner (#totalcms-status-banner, created by
	// TotalFormManager on every admin page). Reusing it keeps bulk actions on
	// the same processing/success/error visual language as form saves. Pure
	// CSS — toggling the class is all that's needed.
	setBanner(status) {
		const banner = document.getElementById('totalcms-status-banner');
		if (!banner) return;
		['processing', 'success', 'error', 'warning'].forEach(s => banner.classList.remove(s));
		if (status) banner.classList.add(status);
	}

	async runDelete() {
		if (this.deleting) return; // guard against a double-submit
		const ids = [...this.selected];
		if (ids.length === 0) return;

		if (!window.confirm(`Delete ${ids.length} item${ids.length === 1 ? '' : 's'}? This cannot be undone.`)) {
			return;
		}

		const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
		const url   = `${this.api}/collections/${this.collection}/bulk/delete`;

		this.deleting = true;
		this.setBanner('processing'); // spinning overlay while the batch runs

		try {
			const res = await fetch(url, {
				method  : 'POST',
				headers : { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
				body    : JSON.stringify({ ids }),
			});

			let data = {};
			try { data = await res.json(); } catch { /* non-JSON error body */ }

			// Request rejected (403 from access/system-collection guards, 4xx/5xx).
			// Warn and stay put — never silently reload past an error.
			if (!res.ok) {
				this.setBanner(null);
				this.deleting = false;
				window.alert(data?.error?.message || `Bulk delete failed (HTTP ${res.status}).`);
				return;
			}

			// Partial failure: some ids couldn't be removed. Surface which ones
			// and do NOT reload — a reload would hide that anything went wrong.
			const failed = Array.isArray(data.failed) ? data.failed : [];
			if (failed.length > 0) {
				this.setBanner(null);
				this.deleting = false;
				window.alert(
					`${data.deleted} deleted, but ${failed.length} could not be removed:\n` +
					failed.join(', '),
				);
				return;
			}

			// Clean success — full reload (like the single-object delete's
			// reload:true) so the header count, pagination, and performance
			// warning all refresh. Leave the processing banner up; it goes away
			// with the page.
			window.location.reload();
		} catch (err) {
			this.setBanner(null);
			this.deleting = false;
			console.error('Bulk delete failed:', err);
			window.alert('Bulk delete failed. Please try again.');
		}
	}

	initCloneDialog() {
		const dialogEl = this.wrapper.querySelector('.dialog-clone-object');
		if (dialogEl) {
			this.dialog = new Dialog(dialogEl);
		}
	}

	openCloneDialog(target) {
		const row = target.closest('tr[data-object-id]');
		if (!row || !this.dialog) return;

		const id = row.dataset.objectId;
		const dialogInput = this.dialog.dialog.querySelector("input[name='id']");
		const dialogForm = this.dialog.dialog.querySelector("form");

		if (dialogInput) dialogInput.value = `${id}-copy`;
		if (dialogForm && dialogForm.simpleform) {
			dialogForm.simpleform.route = target.getAttribute("href");
		}
		this.dialog.open();
	}

	openPopover(target) {
		const popover = target.parentNode.querySelector('.object-action-popover');
		if (!popover) return;

		const rect = target.getBoundingClientRect();
		const offset = 10;
		popover.style.top = `${rect.top + window.scrollY - offset}px`;
		popover.style.left = `${rect.left + window.scrollX + offset}px`;
	}

	focusSearchInput() {
		if (this.searchInput) {
			setTimeout(() => this.searchInput.focus(), 100);
		}
	}
}
