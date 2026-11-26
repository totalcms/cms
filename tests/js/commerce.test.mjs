import { initCartDrawer, mountEmbeddedCheckout } from '../../javascript/commerce.js';

//-----------------------------------------------
// The cart drawer: a native <dialog> that a toggle opens, that a click on
// its backdrop closes, and that can open itself when the cart changes.
// jsdom's <dialog> has no showModal() or close(), so both are spies that
// flip `open` the way a browser does.
//-----------------------------------------------

/** Put a page with a drawer in the document, without wiring it. */
function body({ openOnAdd = 'true' } = {}) {
	document.body.innerHTML = `
		<button type="button" id="toggle" data-cart-toggle aria-controls="cart-drawer">Cart</button>
		<button type="button" id="add">Add to cart</button>
		<dialog id="cart-drawer" data-side="right" data-open-on-add="${openOnAdd}">
			<header><button type="button" id="close" data-cart-close>Close</button></header>
			<div id="cart-drawer-body"><p id="inside">A line</p></div>
		</dialog>
	`;
	const dialog = document.getElementById('cart-drawer');
	dialog.showModal = vi.fn(() => { dialog.open = true; });
	dialog.close     = vi.fn(() => { dialog.open = false; dialog.dispatchEvent(new Event('close')); });

	return dialog;
}

function page(options = {}) {
	const dialog = body(options);
	initCartDrawer(document);

	return dialog;
}

const click   = (id) => document.getElementById(id).dispatchEvent(new MouseEvent('click', { bubbles: true }));
const changed = () => document.body.dispatchEvent(new Event('cart-updated', { bubbles: true }));

describe('cart drawer', () => {
	test('opens the drawer from the toggle', () => {
		const dialog = page();

		click('toggle');

		expect(dialog.showModal).toHaveBeenCalledTimes(1);
	});

	test('does not open a drawer that is already open', () => {
		const dialog = page();

		click('toggle');
		click('toggle');
		changed();

		expect(dialog.showModal).toHaveBeenCalledTimes(1);
	});

	test('opens on cart-updated only when open-on-add is true', () => {
		const opens = page({ openOnAdd: 'true' });
		changed();
		expect(opens.showModal).toHaveBeenCalledTimes(1);

		const stays = page({ openOnAdd: 'false' });
		changed();
		expect(stays.showModal).not.toHaveBeenCalled();
	});

	test('closes from its close button', () => {
		const dialog = page();
		click('toggle');

		click('close');

		expect(dialog.close).toHaveBeenCalledTimes(1);
	});

	test('closes on a backdrop click but not on a click inside', () => {
		const dialog = page();
		click('toggle');

		click('inside');
		expect(dialog.close).not.toHaveBeenCalled();

		// A click on the backdrop lands on the dialog element itself.
		dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		expect(dialog.close).toHaveBeenCalledTimes(1);
	});

	// A dialog gives focus to the first control inside it, which here is the
	// close button: it would be ringed every time the cart opens.
	test('puts focus on the drawer itself when it opens, not on its close button', () => {
		const dialog = page();

		click('toggle');

		expect(document.activeElement).toBe(dialog);
	});

	test('returns focus to the toggle on close', () => {
		const dialog = page();
		const toggle = document.getElementById('toggle');
		click('toggle');
		document.getElementById('close').focus();

		dialog.close();

		expect(document.activeElement).toBe(toggle);
	});

	test('returns focus to where it was when the cart opened it', () => {
		const dialog = page();
		const add    = document.getElementById('add');
		add.focus();
		changed();

		dialog.close();

		expect(document.activeElement).toBe(add);
	});

	// HTMX swaps the whole body when the visitor goes Back or Forward, and a
	// site may do the same with hx-boost. The drawer on the page is then a new
	// element, and nothing runs the script again.
	test('opens the drawer that is on the page now, after the body was replaced', () => {
		const first = page();
		const now   = body();

		click('toggle');

		expect(now.showModal).toHaveBeenCalledTimes(1);
		expect(first.showModal).not.toHaveBeenCalled();
	});

	test('opens on cart-updated and closes from the button after the body was replaced', () => {
		page();
		const now = body();

		changed();
		expect(now.showModal).toHaveBeenCalledTimes(1);

		click('close');
		expect(now.close).toHaveBeenCalledTimes(1);
	});

	test('does nothing on a page without a drawer', () => {
		document.body.innerHTML = '<button type="button" id="toggle" data-cart-toggle>Cart</button>';

		expect(() => { initCartDrawer(document); click('toggle'); changed(); }).not.toThrow();
	});

	test('wires one drawer once, however often it is asked', () => {
		const dialog = page();
		initCartDrawer(document);
		initCartDrawer(document);

		click('toggle');

		expect(dialog.showModal).toHaveBeenCalledTimes(1);
	});
});

//-----------------------------------------------
// On-page checkout: the server prints a mount point naming the payment
// provider's module and the config for this one checkout; the script
// imports the module and hands it the element. The import itself is passed
// in here, since a test has no provider module to fetch.
//-----------------------------------------------

function mountPoint({ module = '/ext/pay/embed.js', config = { clientSecret: 'secret_1' } } = {}) {
	document.body.innerHTML = '<div id="commerce-checkout" data-commerce-embed></div>';
	const el = document.getElementById('commerce-checkout');
	el.dataset.module = module;
	el.dataset.config = JSON.stringify(config);

	return el;
}

describe('on-page checkout', () => {
	test('imports the module and mounts with the parsed config', async () => {
		const el    = mountPoint();
		const mount = vi.fn();
		const load  = vi.fn(async () => ({ mount }));

		await mountEmbeddedCheckout(document, load);

		expect(load).toHaveBeenCalledWith(new URL('/ext/pay/embed.js', document.baseURI).href);
		expect(mount).toHaveBeenCalledWith(el, { clientSecret: 'secret_1' });
		expect(el.dataset.embedState).toBe('mounted');
	});

	test('does not mount the same element twice', async () => {
		mountPoint();
		const mount = vi.fn();
		const load  = vi.fn(async () => ({ mount }));

		await Promise.all([mountEmbeddedCheckout(document, load), mountEmbeddedCheckout(document, load)]);
		await mountEmbeddedCheckout(document, load);

		expect(load).toHaveBeenCalledTimes(1);
		expect(mount).toHaveBeenCalledTimes(1);
	});

	test('never imports a module from another site', async () => {
		const el   = mountPoint({ module: 'https://elsewhere.example/embed.js' });
		const load = vi.fn(async () => ({ mount: vi.fn() }));

		await mountEmbeddedCheckout(document, load);

		expect(load).not.toHaveBeenCalled();
		expect(el.dataset.embedState).toBe('failed');
	});

	test('says so in the page when the form cannot be loaded', async () => {
		const el     = mountPoint();
		const failed = vi.fn();
		el.addEventListener('commerce:embed-failed', failed);

		await mountEmbeddedCheckout(document, async () => { throw new Error('offline'); });

		expect(el.dataset.embedState).toBe('failed');
		expect(el.textContent).toContain('could not be loaded');
		expect(el.querySelector('[role="alert"]')).not.toBeNull();
		expect(failed).toHaveBeenCalledTimes(1);
	});

	test('mounts a form that HTMX swapped into the page', async () => {
		document.body.innerHTML = '';
		const mount = vi.fn();
		const load  = vi.fn(async () => ({ mount }));
		await mountEmbeddedCheckout(document, load);
		expect(load).not.toHaveBeenCalled();

		const el = mountPoint();
		await mountEmbeddedCheckout(document, load);

		expect(mount).toHaveBeenCalledWith(el, { clientSecret: 'secret_1' });
	});
});
