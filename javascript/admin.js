import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import FactoryForm from './totalform/factory';
import Scrollable from './totalform/scrollable';
import AdminList from './totalform/admin-list';
import { Grid, html } from "gridjs";
// import { RowSelection } from "gridjs/plugins/selection";

globalThis.TotalCMS = TotalCMS;

document.addEventListener("DOMContentLoaded", event => {
	const manager = new TotalFormManager();

	const factoryForms = Array.from(document.getElementsByClassName("factory-form"));
	factoryForms.forEach(form => new FactoryForm(form));

	const scrollables = Array.from(document.getElementsByClassName("scrollable"));
	scrollables.forEach(scrollable => new Scrollable(scrollable));

	const adminlists = Array.from(document.getElementsByClassName("admin-list"));
	adminlists.forEach(list => new AdminList(list));

	const tables = Array.from(document.getElementsByClassName("collection-table"));
	tables.forEach(table => {
		const collection = table.dataset.collection;

		const wrapper = document.createElement("div");
		wrapper.classList.add("collection-table-wrapper");
		table.parentNode.insertBefore(wrapper, table);

		// const headers = Array.from(table.getElementsByTagName("th"));
		// headers.map(header => {name : html(header.innerHTML)});

		// console.log(headers);

		// headers.unshift({
		// 	name: "Select",
		// 	plugin: {
		// 		component: RowSelection,
		// 	},
		// });

		const grid = new Grid({
			// columns    : headers,
			from       : table,
			pagination : {
				limit   : 25,
			},
			search      : true,
			sort        : true,
			resizable   : true,
			autoWidth   : true,
			fixedHeader : true,
			language    : {
				search: {
					placeholder : "Filter objects",
				},
			},
		});
		grid.render(wrapper);
		grid.on('rowClick', e => {
			const row = e.currentTarget;
			const cells = Array.from(row.querySelectorAll("td"));
			let id = '';
			cells.forEach(cell => {
				if (cell.dataset.columnId === 'id') id = cell.innerText;
			});
			window.location.href = `${window.location.href}/${id}`;
		});
	});
});
