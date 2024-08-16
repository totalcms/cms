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
		this.initRowListner();
	}

	initRowListner() {
		this.grid.on('rowClick', e => {
			const row = e.currentTarget;
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
		return new Grid({
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
	}
}
