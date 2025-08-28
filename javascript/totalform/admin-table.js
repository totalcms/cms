import QuickAction from '../quickaction';
import { Grid, html } from "gridjs";
// import { RowSelection } from "gridjs/plugins/selection";

//-----------------------------------------------
// Total CMS Collection Table Component
//-----------------------------------------------
export default class AdminTable {

    constructor(table, options = {}) {
		this.table = table;

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
		this.initCloneDialog();
		this.initCellListner();
		this.initActionListner();
		this.initQuickActionListner();
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

	initActionListner() {
		// Popovers
		const buttons = this.wrapper.querySelectorAll("button[popovertarget]");
		buttons.forEach(button => {
			button.addEventListener("pointerdown", e => {
				const popover = button.parentNode.querySelector(".object-action-popover");
				const rect = button.getBoundingClientRect();
				const offset = 10;
				popover.style.top = `${rect.top + window.scrollY - offset}px`;
				popover.style.left = `${rect.left + window.scrollX + offset}px`;
			});
		});
		// Delete Objects
		const deletes = this.wrapper.querySelectorAll(".delete>a");
		deletes.forEach(link => {
			link.addEventListener("quickaction-success", e => {
				const row = link.closest(".gridjs-tr");
				row.remove();
				this.grid.updateConfig({
					data: this.grid.config.data.filter(item => item[0] !== data.id),
				}).forceRender();
			});
		});
		const clones = this.wrapper.querySelectorAll(".clone>a");
		clones.forEach(link => {
			link.addEventListener("pointerdown", e => {
				const row = link.closest(".gridjs-tr");
				const id = row.querySelector("td[data-column-id='id']").innerText;
				this.dialog.dialog.querySelector("input[name='id']").value = `${id}-copy`;
				this.dialog.dialog.querySelector("form").simpleform.route = link.getAttribute("href");
				this.dialog.open();
			});
		});
	}

	initQuickActionListner() {
		const buttons = Array.from(this.wrapper.getElementsByClassName("cms-quick-action"));
		buttons.forEach(link => new QuickAction(link));
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

	initCellListner() {
		this.grid.on('cellClick', e => {
			const cell = e.currentTarget;
			// Ignore clicks on buttons and links
			if (cell.querySelector("button,a")) return;

			const row = cell.closest(".gridjs-tr");
			const cells = Array.from(row.querySelectorAll("td"));
			let id = '';
			cells.forEach(cell => {
				if (cell.dataset.columnId === 'id') id = cell.innerText;
			});
			if (id) {
				window.location.href = `${window.location.href}/${id}`;
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
			if (prevState.status < state.status) {
				if (prevState.status === 2 && state.status === 3) {
					this.gridReady();
					// Always force re-render for large datasets to fix pagination issues
					this.fixPaginationIssues();
				}
			}
		});

		return grid;
	}
}
