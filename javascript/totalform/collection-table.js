import { Grid, html } from "gridjs";
// import { RowSelection } from "gridjs/plugins/selection";

//-----------------------------------------------
// Total CMS Collection Table Component
//-----------------------------------------------
export default class CollectionTable {

    constructor(table, options = {}) {
		this.table = table;

		this.options = Object.assign({
			limit       : 25,
			placeholder : "Filter objects",
		}, options);

		this.collection = table.dataset.collection;
		this.wrapper    = this.createWrapper();

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
			window.location.href = `${window.location.href}/${id}`;
		});
	}

	createWrapper() {
		const wrapper = document.createElement("div");
		wrapper.classList.add("collection-table-wrapper");
		this.table.parentNode.insertBefore(wrapper, this.table);
		return wrapper;
	}

	initGrid() {
		return new Grid({
			from       : this.table,
			pagination : {
				limit   : this.options.limit,
			},
			search      : true,
			sort        : true,
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
