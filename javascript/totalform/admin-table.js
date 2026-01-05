import QuickAction from '../quickaction';
import { Grid, html } from "gridjs";
// import { RowSelection } from "gridjs/plugins/selection";

//-----------------------------------------------
// Total CMS Collection Table Component
//-----------------------------------------------
export default class AdminTable {

    constructor(table, options = {}) {
		this.table = table;
		this.gridInitialized = false; // Flag to prevent multiple gridReady executions

		this.options = Object.assign({
			pagination  : table.dataset.limit !== undefined,
			limit       : table.dataset.limit || 25,
			search      : table.dataset.search !== undefined,
			sort        : table.dataset.sort !== undefined,
			placeholder : table.dataset.placeholder || "Filter...",
		}, options);

		this.wrapper = this.createWrapper();

		this.pagination = false;
		if (this.options.pagination) {
			this.pagination = {
				limit: this.options.limit,
			};
		}

		this.grid = this.initGrid();
		this.grid.render(this.wrapper);
		this.table.admintable = this;
	}

	gridReady() {
		// Prevent multiple executions
		if (this.gridInitialized) return;
		this.gridInitialized = true;

		console.log('AdminTable: Grid ready - initializing once');
		this.initCloneDialog();
		this.initDelegatedEventListeners(); // Replace individual listeners with delegation
		this.initCellClickListener(); // GridJS cellClick is already efficient
		this.focusSearchInput();
		this.fixPaginationIssues();
	}

	/**
	 * Force re-render to fix pagination issues with large datasets
	 * This addresses the GridJS bug where pagination breaks on initial render
	 */
	fixPaginationIssues() {
		const rowCount = this.table.querySelectorAll('tbody tr').length;

		// Only apply fix for large datasets where the issue occurs (aligns with throttle threshold)
		if (rowCount > 400 && this.pagination) {
			// Grid is fully rendered at this point, just add small delay for safety
			setTimeout(() => {
				console.log(`AdminTable: Applying pagination fix for ${rowCount} rows`);
				this.grid.forceRender();
			}, 100); // Reduced delay since grid is already ready
		}
	}

	initCloneDialog() {
		this.dialog = new Dialog(document.querySelector(".dialog-clone-object"));
		// const form = this.dialog.dialog.querySelector("form");
		// form.addEventListener("simpleform:success", e => {
		// 	setTimeout(() => this.dialog.close(), 1000);
		// });
	}

	initDelegatedEventListeners() {
		// Single delegated listener for all grid interactions
		this.wrapper.addEventListener('pointerdown', (e) => this.handlePointerDown(e));
		this.wrapper.addEventListener('quickaction-success', (e) => this.handleDeleteActionSuccess(e));
	}

	handlePointerDown(e) {
		const target = e.target;

		// Handle popover buttons
		if (target.matches("button[popovertarget]")) {
			this.openPopover(target);
			return;
		}

		// Handle clone links
		if (target.matches(".clone > a")) {
			this.openCloneDialog(target);
			return;
		}
	}

	handleDeleteActionSuccess(e) {
		const target = e.target;
		// Handle delete success
		if (target.matches(".delete-action")) {
			const row = target.closest(".gridjs-tr");
			if (row) {
				row.remove();
				// Update grid's internal data state if available
				// GridJS stores data in config.store.state.data when initialized from table
				const gridData = this.grid.config.store?.state?.data;
				if (gridData && Array.isArray(gridData)) {
					const deletedId = e.detail?.id;
					const filteredData = gridData.filter(item => {
						// Data can be array of arrays or array of objects
						const itemId = Array.isArray(item) ? item[0] : item.id;
						return itemId !== deletedId;
					});
					this.grid.updateConfig({ data: filteredData }).forceRender();
				}
			}
		}
	}

	openCloneDialog(target) {
		const row = target.closest(".gridjs-tr");
		if (row) {
			const idCell = row.querySelector("td[data-column-id='id']");
			if (idCell) {
				const id = idCell.innerText;
				const dialogInput = this.dialog.dialog.querySelector("input[name='id']");
				const dialogForm = this.dialog.dialog.querySelector("form");

				if (dialogInput) dialogInput.value = `${id}-copy`;
				if (dialogForm && dialogForm.simpleform) {
					dialogForm.simpleform.route = target.getAttribute("href");
				}
				this.dialog.open();
			}
		}
	}

	openPopover(target) {
		const popover = target.parentNode.querySelector(".object-action-popover");
		if (popover) {
			const rect = target.getBoundingClientRect();
			const offset = 10;
			popover.style.top = `${rect.top + window.scrollY - offset}px`;
			popover.style.left = `${rect.left + window.scrollX + offset}px`;
			this.initDeleteActions(popover);
		}
	}

	initDeleteActions(target) {
		// Initialize Delete Action QuickAction
		new QuickAction(target.querySelector(".delete-action"));
	}

	focusSearchInput() {
		if (this.options.search) {
			// GridJS creates the search input with class 'gridjs-search'
			const searchInput = this.wrapper.querySelector('.gridjs-search input[type="search"]');
			if (searchInput) {
				// Small delay to ensure the input is fully rendered and focusable
				setTimeout(() => {
					searchInput.focus();
				}, 100);
			}
		}
	}

	initCellClickListener() {
		// Use GridJS's built-in cellClick event (this is already efficient)
		this.grid.on('cellClick', (e) => {
			const cell = e.currentTarget;
			// Ignore clicks on buttons and links
			if (cell.querySelector("button,a")) return;

			const row = cell.closest(".gridjs-tr");
			const idCell = row.querySelector("td[data-column-id='id']");
			if (idCell) {
				const id = idCell.innerText;
				if (id) {
					const url = `${window.location.href}/${id}`;
					// Support cmd+click (Mac) / ctrl+click (Windows/Linux) to open in new tab
					if (e.metaKey || e.ctrlKey) {
						window.open(url, '_blank');
					} else {
						window.location.href = url;
					}
				}
			}
		});
	}

	createWrapper() {
		const wrapper = document.createElement("div");
		wrapper.classList.add("admin-table-wrapper");
		this.table.parentNode.insertBefore(wrapper, this.table);
		return wrapper;
	}

	initGrid() {
		const rowCount = this.table.querySelectorAll('tbody tr').length;

		// Dynamic throttle based on data size to prevent pagination issues
		// Formula: rowCount / 4, max 2000ms, no throttle if < 400ms
		let processingThrottleMs = 0; // Default: no throttle
		if (rowCount > 400) { // 400/4 = 100ms minimum threshold
			processingThrottleMs = Math.min(Math.floor(rowCount / 4), 2000);
		}

		const gridConfig = {
			from        : this.table,
			pagination  : this.pagination,
			search      : this.options.search,
			sort        : this.options.sort,
			resizable   : true,
			autoWidth   : true,
			fixedHeader : true,
			language    : {
				search: {
					placeholder : this.options.placeholder,
				},
			},
		};

		// Only add throttle if needed (keeps config clean for small datasets)
		if (processingThrottleMs > 0) {
			gridConfig.processingThrottleMs = processingThrottleMs;
			console.log(`AdminTable: Applied ${processingThrottleMs}ms throttle for ${rowCount} rows`);
		}

		const grid = new Grid(gridConfig);

		grid.config.store.subscribe((state, prevState) => {
			// GridJS status transitions: typically 0=init, 1=loading, 2=loaded, 3=rendered
			// We wait for 2→3 transition to ensure grid is fully rendered before initializing
			if (prevState.status === 2 && state.status === 3) {
				this.gridReady();
			}
		});

		return grid;
	}
}
