import { readFileSync } from 'node:fs';

//-----------------------------------------------
// admin/sidebar-details.twig remembers which sidebar groups (<details id>)
// are open. It is an inline script in a Twig template, so the test lifts the
// <script> body out of the file and runs it against a fake sidebar.
//
// The bug this pins: the memory used to be keyed on the first three URL
// segments, so /admin/schemas and /admin/schemas/blog were different pages —
// collapse a group in the list, open a schema, everything reopened.
//-----------------------------------------------

const template = readFileSync('resources/templates/admin/sidebar-details.twig', 'utf8');
const script   = template.slice(template.indexOf('<script>') + '<script>'.length, template.indexOf('</script>'));

function mountSidebar({ path, base = '/admin/', groups }) {
	window.history.pushState({}, '', path);
	document.head.innerHTML = `<base href="${base}">`;
	document.body.innerHTML = `<aside class="dash-content-sidebar"><nav>${groups.map(g => `
		<details id="${g.id}"${g.open ? ' open' : ''}${g.serverOpen ? ' data-server-open="true"' : ''}>
			<summary>${g.id}</summary>
			<ul>${(g.items || []).map(i => `<li><a href="#"${i.active ? ' class="active"' : ''}>${i.label}</a></li>`).join('')}</ul>
		</details>`).join('')}</nav></aside>`;

	new Function(script)();
}

const settle = () => new Promise(resolve => setTimeout(resolve, 5));

beforeEach(() => {
	localStorage.clear();
	Element.prototype.scrollIntoView = vi.fn(); // jsdom does not implement it
});

describe('sidebar group memory', () => {
	test('a group collapsed on the list page stays collapsed on a detail page under it', async () => {
		mountSidebar({ path: '/admin/schemas', groups: [{ id: 'category-internal', open: true }] });
		const details = document.getElementById('category-internal');

		details.querySelector('summary').click();
		if (details.open) details.open = false; // jsdom may not implement summary activation
		await settle();

		expect(localStorage.getItem('sidebar-details-schemas-category-internal')).toBe('false');

		mountSidebar({ path: '/admin/schemas/blog', groups: [{ id: 'category-internal', open: true }] });
		expect(document.getElementById('category-internal').open).toBe(false);
	});

	test('the scope is the section after the dashboard base, so a sub-path install behaves the same', () => {
		localStorage.setItem('sidebar-details-docs-category-twig', 'false');

		mountSidebar({ path: '/mysite/admin/docs/twig/overview', base: '/mysite/admin/', groups: [{ id: 'category-twig', open: true }] });

		expect(document.getElementById('category-twig').open).toBe(false);
	});

	test('a remembered "closed" never hides the current page', () => {
		localStorage.setItem('sidebar-details-schemas-category-internal', 'false');

		mountSidebar({ path: '/admin/schemas/seo', groups: [
			{ id: 'category-internal', open: true, items: [{ label: 'seo', active: true }] },
			{ id: 'category-builtin', open: true, items: [{ label: 'blog' }] },
		] });

		expect(document.getElementById('category-internal').open).toBe(true);
	});

	test('a server-opened group opens regardless of memory', () => {
		localStorage.setItem('sidebar-details-docs-category-forms', 'false');

		mountSidebar({ path: '/admin/docs/forms/builder', groups: [{ id: 'category-forms', serverOpen: true }] });

		expect(document.getElementById('category-forms').open).toBe(true);
	});

	test('programmatic opens are not recorded as the user\'s choice', async () => {
		localStorage.setItem('sidebar-details-schemas-category-internal', 'false');

		mountSidebar({ path: '/admin/schemas/seo', groups: [{ id: 'category-internal', items: [{ label: 'seo', active: true }] }] });
		await settle();

		// Forced open for the active page, but the memory still says closed.
		expect(document.getElementById('category-internal').open).toBe(true);
		expect(localStorage.getItem('sidebar-details-schemas-category-internal')).toBe('false');
	});

	test('the current page\'s link is scrolled into view once the groups are settled', () => {
		mountSidebar({ path: '/admin/schemas/seo', groups: [
			{ id: 'category-builtin', open: true, items: [{ label: 'blog' }] },
			{ id: 'category-internal', items: [{ label: 'seo', active: true }] },
		] });

		const active = document.querySelector('.dash-content-sidebar .active');
		expect(active.scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
	});

	test('a sidebar with no active item does not scroll', () => {
		mountSidebar({ path: '/admin/schemas', groups: [{ id: 'category-builtin', open: true, items: [{ label: 'blog' }] }] });

		expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
	});
});
