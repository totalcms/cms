import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import SimpleForm from './totalform/simpleform';
import Scrollable from './totalform/scrollable';
import FilterList from './totalform/filter-list';
import AdminTable from './totalform/admin-table';
import QuickAction from './quickaction';
import ClipButton from './clipboard-button';
import JobQueueStatsTable from './jobqueue-stats';
import JSONField from './totalform/json';
import SelectField from './totalform/select';
import TotalField from './totalform/totalfield';
import initExternalLinks from './external-links';
import './codemirror-bundle'; // Include CodeMirror functionality in admin

globalThis.TotalCMS = TotalCMS;

document.addEventListener("DOMContentLoaded", event => {
	const manager = new TotalFormManager();

	const simpleForms = Array.from(document.getElementsByClassName("simple-form"));
	simpleForms.forEach(form => new SimpleForm(form));

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

	const tables = Array.from(document.getElementsByClassName("admin-table"));
	tables.forEach(table => new AdminTable(table));

	const reindex = Array.from(document.getElementsByClassName("cms-quick-action"));
	reindex.forEach(link => new QuickAction(link));

	// This should be moved to a content.js file
	const embeds = Array.from(document.getElementsByClassName("cms-video-embed"));
	embeds.forEach(iframe => iframe.src = iframe.dataset.src);

	const copyButtons = Array.from(document.getElementsByClassName("cms-clip-button"));
	copyButtons.forEach(button => new ClipButton(button));

	const jobqueueStats = Array.from(document.getElementsByClassName("jobqueue-stats"));
	jobqueueStats.forEach(table => new JobQueueStatsTable(table));

	initExternalLinks();
});
