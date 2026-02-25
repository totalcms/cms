import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import QuickAction from './quickaction';
import SimpleForm from './totalform/simpleform';
import Scrollable from './totalform/scrollable';
import FilterList from './totalform/filter-list';
import AdminTable from './totalform/admin-table';
import ClipButton from './clipboard-button';
import JobQueueStatsTable from './jobqueue-stats';
import JSONField from './totalform/json';
import SelectField from './totalform/select';
import TotalField from './totalform/totalfield';
import SlugifyInput from './totalform/slugify-inputs';
import DevModeToggle from './devmode';
import ThemeSwitcher from './theme-switcher';
import initExternalLinks from './external-links';
import DocSearch from './totalform/doc-search';
import initDocHighlight from './doc-highlight';
import './codemirror-bundle'; // Include CodeMirror functionality in admin

globalThis.TotalCMS = TotalCMS;
globalThis.QuickAction = QuickAction;

// Inject CSRF token into all HTMX requests
document.addEventListener('htmx:config:request', (e) => {
	const token = document.querySelector('meta[name="csrf-token"]');
	if (token) e.detail.headers['X-CSRF-Token'] = token.content;
});

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

	const copyButtons = Array.from(document.getElementsByClassName("cms-clip-button"));
	copyButtons.forEach(button => new ClipButton(button));

	const jobqueueStats = Array.from(document.getElementsByClassName("jobqueue-stats"));
	jobqueueStats.forEach(table => new JobQueueStatsTable(table));

	const inputs = Array.from(document.getElementsByClassName('slugify-input'));
	inputs.forEach(input => new SlugifyInput(input));

	const devmodeToggle = document.querySelector('input[name="devmode"][type="checkbox"]');
	if (devmodeToggle) {
		// Get remaining seconds from a global variable or data attribute
		const remainingSeconds = globalThis.DEVMODE_REMAINING_SECONDS || 0;
		new DevModeToggle(devmodeToggle, { remainingSeconds });
	}

	// Initialize theme switcher
	const themeSwitcher = document.querySelector('.theme-buttons');
	if (themeSwitcher) {
		new ThemeSwitcher(themeSwitcher);
	}

	// Mobile menu toggle
	const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
	const mobileOverlay = document.querySelector('.mobile-overlay');
	const adminDashboard = document.querySelector('.admin-dashboard');

	if (mobileMenuToggle && mobileOverlay && adminDashboard) {
		mobileMenuToggle.addEventListener('click', () => {
			const isOpen = adminDashboard.classList.contains('menu-open');

			// Toggle menu state
			adminDashboard.classList.toggle('menu-open');
			mobileMenuToggle.classList.toggle('active');

			// Toggle overlay
			mobileOverlay.classList.toggle('active');
			mobileOverlay.style.display = !isOpen ? 'block' : 'none';
		});

		mobileOverlay.addEventListener('click', () => {
			// Close menu
			adminDashboard.classList.remove('menu-open');
			mobileMenuToggle.classList.remove('active');
			mobileOverlay.classList.remove('active');
			mobileOverlay.style.display = 'none';
		});
	}

	// Initialize documentation search on docs homepage
	const docSearchContainer = document.getElementById('doc-search-container');
	if (docSearchContainer) {
		new DocSearch(docSearchContainer);
	}

	// Highlight search terms in docs
	initDocHighlight();

	initExternalLinks();
});
