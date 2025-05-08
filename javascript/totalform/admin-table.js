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
	}

	gridReady() {
		this.initCellListner();
		this.initActionListner();
	}

	initActionListner() {
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
		const grid = new Grid({
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
		});

		grid.config.store.subscribe((state, prevState) => {
			if (prevState.status < state.status) {
				if (prevState.status === 2 && state.status === 3) {
					this.gridReady();
				}
			}
		});

		return grid;
	}
}
