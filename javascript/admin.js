import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import FactoryForm from './totalform/factory';
import Scrollable from './totalform/scrollable';
import AdminList from './totalform/admin-list';
import CollectionTable from './totalform/collection-table';

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
	tables.forEach(table => new CollectionTable(table));
});
