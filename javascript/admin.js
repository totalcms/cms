import TotalFormManager from './totalform/totalform-manager';
import TotalCMS from './totalcms';
import QuickAction from './quickaction';
import SimpleForm from './totalform/simpleform';
import Scrollable from './totalform/scrollable';
import FilterList from './totalform/filter-list';
import TreeView from './tree-view';
import initBuilderPageSortable from './builder-page-sortable';
import AdminTable from './totalform/admin-table';
import SortableTable from './totalform/sortable-table';
import ClipButton from './clipboard-button';
import JSONField from './totalform/json';
import SelectField from './totalform/select';
import TotalField from './totalform/totalfield';
import SlugifyInput from './totalform/slugify-inputs';
import DevModeToggle from './devmode';
import ThemeSwitcher from './theme-switcher';
import initExternalLinks from './external-links';
import DocSearch from './totalform/doc-search';
import initDocHighlight from './doc-highlight';
import PasskeyLogin from './passkey-login';
import PasskeyManager from './passkeys';
import tcmsConfirm from './confirm-dialog';
import QuickNav from './quick-nav';
import './codemirror-bundle'; // Include CodeMirror functionality in admin

globalThis.TotalCMS = TotalCMS;
globalThis.QuickAction = QuickAction;
globalThis.JSONField = JSONField;

// Idempotency guard: a page can end up including this bundle twice with
// DIFFERENT cache-buster query strings (e.g. a legacy hardcoded
// `?v={{ cms.version }}` tag plus the adminAssetsBody() tag) — the browser
// module map only dedupes identical URLs, so the module body executes
// twice. Without this guard every listener below double-binds: one save
// click became two saves (duplicate mailer-action emails, "object already
// exists" races on new objects). Initialize once; later executions no-op.
const duplicateLoad = globalThis.__tcmsAdminLoaded === true;
globalThis.__tcmsAdminLoaded = true;
if (duplicateLoad) {
	console.warn("Total CMS admin.js loaded more than once — skipping duplicate initialization. Check for multiple admin.js script tags with differing cache-busters.");
}

// Inject CSRF token into all HTMX requests.
//
// HTMX 4 changed the `htmx:config:request` payload from a flat
// `{ headers, parameters, ... }` (HTMX 1/2) to `{ ctx }`, where the
// outgoing headers live at `ctx.request.headers`. Reading the old
// shape silently no-ops on HTMX 4 — the meta tag is found, but the
// header is written into an undefined object, so the request hits
// CSRFProtectionMiddleware bare and 403s. Things like the Twig
// playground render form (hx-post) and the inline reorder/preview
// HTMX endpoints all depend on this listener.
if (!duplicateLoad) document.addEventListener('htmx:config:request', (e) => {
	const token = document.querySelector('meta[name="csrf-token"]');
	const headers = e.detail?.ctx?.request?.headers;
	if (token && headers) headers['X-CSRF-Token'] = token.content;
});

// Intercept hx-confirm and route through the custom countdown dialog
if (!duplicateLoad) document.body.addEventListener('htmx:confirm', (e) => {
	const elt = e.target;
	const message = e.detail?.ctx?.confirm || elt?.getAttribute?.('hx-confirm');
	if (!elt || !message) return;

	e.preventDefault();

	const countdownAttr = elt.getAttribute('data-confirm-countdown');
	const countdown = countdownAttr !== null ? parseInt(countdownAttr, 10) : null;

	tcmsConfirm({
		title        : elt.getAttribute('data-confirm-title') || '',
		message,
		confirmLabel : elt.getAttribute('data-confirm-label') || undefined,
		cancelLabel  : elt.getAttribute('data-confirm-cancel') || undefined,
		countdown,
	}).then((ok) => {
		if (ok) e.detail.issueRequest(true);
	});
});

document.addEventListener("DOMContentLoaded", event => {
	if (duplicateLoad) return;
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
		const treeRoot = sidebar.querySelector('[data-tree-view]');
		if (treeRoot) {
			// Hierarchical sidebar (Builder) — single TreeView handles filter + folders
			new TreeView(treeRoot, {
				treeSelector : '.tree-view',
				filterInput  : sidebar.querySelector('input[type="search"]'),
				stripes      : false,
			});
			// Drag-drop reordering for the Site Pages tree
			initBuilderPageSortable(sidebar);
			return;
		}

		const lists = Array.from(sidebar.querySelectorAll('.links ul'));
		lists.forEach(list => {
			const input      = sidebar.querySelector('input[type="search"]');
			const filterlist = new FilterList(input, list, {
				scrollable     : false,
				maintainHeight : false,
			});
		});
	});

	// HTMX-powered collection tables
	const tableWrappers = Array.from(document.getElementsByClassName("admin-table-wrapper"));
	tableWrappers.forEach(wrapper => new AdminTable(wrapper));

	// Sortable static tables (logs page)
	const sortableTables = Array.from(document.querySelectorAll("table.admin-table[data-sort]"));
	sortableTables.forEach(table => new SortableTable(table));

	const copyButtons = Array.from(document.getElementsByClassName("cms-clip-button"));
	copyButtons.forEach(button => new ClipButton(button));

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

	// Passkey login button (on login page)
	const passkeyLoginBtn = document.querySelector('.cms-passkey-login');
	if (passkeyLoginBtn) new PasskeyLogin(passkeyLoginBtn);

	// Passkey manager (on profile page)
	const passkeyMgr = document.getElementById('passkeys-manager');
	if (passkeyMgr) new PasskeyManager(passkeyMgr);

	initExternalLinks();

	// Wire cache-clear buttons (.cms-clear-cache). Stacks admin pages render
	// these; the T3 dashboard has none, so this is a no-op there. Previously
	// wired by an inline bootstrap script the Stacks plugin emitted — owning
	// it here lets stack pages drop that inline script entirely. The API
	// base is derived from this bundle's own script tag, which carries it on
	// every page that loaded us.
	if (document.querySelector(".cms-clear-cache")) {
		const adminScript = document.querySelector('script[src*="/assets/admin.js"]');
		if (adminScript) {
			const apiBase = adminScript.src.split("/assets/")[0];
			new TotalCMS({ url: apiBase }).clearTwigCacheListeners();
		}
	}

	// Quick navigation (Shift+Cmd+O)
	if (window.TCMS_QUICK_NAV) new QuickNav();
});
