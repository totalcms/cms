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

		this.initSortHeaders();
		this.initDelegatedListeners();
		this.initCloneDialog();
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

		// Include search term if active
		const search = this.searchInput?.value?.trim();
		if (search) {
			params.set('search', search);
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
		if (popover) {
			const rect = target.getBoundingClientRect();
			const offset = 10;
			popover.style.top = `${rect.top + window.scrollY - offset}px`;
			popover.style.left = `${rect.left + window.scrollX + offset}px`;
		}
	}

	focusSearchInput() {
		if (this.searchInput) {
			setTimeout(() => this.searchInput.focus(), 100);
		}
	}
}
