import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import FactoryForm from './totalform/factory';
import Scrollable from './totalform/scrollable';
import FilterList from './totalform/filter-list';
import CollectionTable from './totalform/collection-table';

globalThis.TotalCMS = TotalCMS;

document.addEventListener("DOMContentLoaded", event => {
	const manager = new TotalFormManager();

	const factoryForms = Array.from(document.getElementsByClassName("factory-form"));
	factoryForms.forEach(form => new FactoryForm(form));

	const scrollables = Array.from(document.getElementsByClassName("scrollable"));
	scrollables.forEach(scrollable => new Scrollable(scrollable));

	const adminlists = Array.from(document.getElementsByClassName("admin-list"));
	adminlists.forEach(list => {
		const content    = list.querySelector('.list-content');
		const input      = list.querySelector('input[type="search"]');
		const filterlist = new FilterList(input, content);
	});

	const dashboardSidebar = Array.from(document.getElementsByClassName("dash-content-sidebar"));
	dashboardSidebar.forEach(sidebar => {
		const lists    = Array.from(sidebar.querySelectorAll('.links ul'));
		lists.forEach(list => {
			const input      = sidebar.querySelector('input[type="search"]');
			const filterlist = new FilterList(input, list, {
				scrollable     : false,
				maintainHeight : false,
			});
		});
	});

	const tables = Array.from(document.getElementsByClassName("collection-table"));
	tables.forEach(table => new CollectionTable(table));
});
