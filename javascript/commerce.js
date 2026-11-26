/**
 * The storefront's own script: the slide-out cart, the payment form of an
 * on-page checkout, and the checkout form's "Where you are" fields.
 *
 * It is small on purpose. Everything the cart shows comes from the server,
 * and each piece of it (the drawer's body, the count badge, the widget)
 * reloads itself through HTMX when the cart changes. All this does is open
 * and close the drawer, and hand a payment provider's module the place the
 * server made for its form.
 */

const TOGGLE = '[data-cart-toggle]';
const CLOSE  = '[data-cart-close]';
const DRAWER = '#cart-drawer';

/** The documents whose drawer is wired, so that asking again adds nothing. */
const wired = new WeakSet();

/**
 * Wire the cart drawer of `root`. Safe to call again: a document is wired
 * once.
 *
 * Every listener is on the document and finds the drawer when it is needed.
 * HTMX replaces the whole body when the visitor goes Back or Forward (and a
 * site may do the same with hx-boost), without running this script again:
 * the drawer on the page is then a new element, and one found when the page
 * loaded would no longer be in it.
 *
 * @param {Document} root
 */
export function initCartDrawer(root = document) {
	if (wired.has(root)) {
		return;
	}
	wired.add(root);

	const drawer = () => root.querySelector(DRAWER);

	// What had focus when the drawer opened, to give it back when it closes.
	let opener = null;

	const open = (from) => {
		const dialog = drawer();
		if (!dialog || dialog.open) {
			return;
		}
		opener = from;
		dialog.showModal();
		// A dialog gives focus to the first control inside it. Here that is the
		// close button, which would be ringed every time the cart opens, so
		// focus goes to the drawer itself: Tab then reaches the button first.
		if (!dialog.hasAttribute('tabindex')) {
			dialog.tabIndex = -1;
		}
		dialog.focus();
	};

	root.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof Element)) {
			return;
		}

		const toggle = target.closest(TOGGLE);
		if (toggle) {
			open(toggle);
			return;
		}
		// The dialog fills its own box, so a click that lands on the dialog
		// itself, and not on anything inside it, landed on the backdrop.
		const dialog = drawer();
		if (dialog && (target === dialog || (target.closest(CLOSE) && dialog.contains(target)))) {
			dialog.close();
		}
	});

	// Esc closes a dialog without going through the code above; `close` is
	// the one event every way of closing ends in. It does not bubble, so it
	// is caught on its way down.
	root.addEventListener('close', (event) => {
		if (!(event.target instanceof Element) || !event.target.matches(DRAWER)) {
			return;
		}
		if (opener instanceof HTMLElement && opener.isConnected) {
			opener.focus();
		}
		opener = null;
	}, true);

	// Every change to the cart is announced on the page (the server sends
	// `HX-Trigger: cart-updated`). Opening on it is how "added to cart" shows.
	root.addEventListener('cart-updated', () => {
		if (drawer()?.dataset.openOnAdd === 'true') {
			open(root.activeElement);
		}
	});
}

const EMBED = '[data-commerce-embed]';

/**
 * Mount the payment provider's own form in each place the server asked for
 * one (`commerce/checkout-embedded.twig`). The mount point names the
 * provider's module and carries the config for this one checkout; the module
 * exports `mount(element, config)`.
 *
 * Safe to call again: a mount point is used once.
 *
 * @param {Document} root
 * @param {(url: string) => Promise<{mount: (el: Element, config: object) => unknown}>} load
 *        how a module is imported; a test passes its own
 */
export async function mountEmbeddedCheckout(root = document, load = (url) => import(url)) {
	const pending = [...root.querySelectorAll(EMBED)].filter((el) => !el.dataset.embedState);

	await Promise.all(pending.map(async (el) => {
		el.dataset.embedState = 'loading';
		try {
			// A provider's module is served by this site (its extension's own
			// asset). A mount point naming any other origin did not come from
			// the store, and is never imported.
			const url = new URL(el.dataset.module ?? '', root.baseURI);
			if (url.origin !== new URL(root.baseURI).origin) {
				throw new Error(`Refused a payment module from ${url.origin}`);
			}

			const module = await load(url.href);
			await module.mount(el, JSON.parse(el.dataset.config ?? '{}'));
			el.dataset.embedState = 'mounted';
		} catch (error) {
			el.dataset.embedState = 'failed';
			const notice = root.createElement('p');
			notice.setAttribute('role', 'alert');
			notice.className   = 'commerce-checkout-error';
			notice.textContent = 'The payment form could not be loaded. Reload the page to try again.';
			el.replaceChildren(notice);
			el.dispatchEvent(new CustomEvent('commerce:embed-failed', { bubbles: true, detail: { error } }));
		}
	}));
}

const LOCATION = '[data-commerce-location]';
const PROVIDER = '#commerce-checkout-form [name="provider"]';

/** The documents whose checkout form is watched, so that asking again adds nothing. */
const watched = new WeakSet();

/**
 * Show the checkout form's "Where you are" fields only while they are
 * needed. The store asks them to work out tax from its own rates. A way to
 * pay that works the tax out itself (`data-works-out-tax`) asks the buyer on
 * its own page, so while one is chosen the fields are put away and
 * disabled: a disabled field is not sent, and is not required.
 *
 * Safe to call again: it sets the fields for the form on the page now, and
 * a document is watched once. Like the drawer, it finds the form when it is
 * needed, because the page may have replaced it since.
 *
 * @param {Document} root
 */
export function syncCheckoutLocation(root = document) {
	const apply = () => {
		const fields = root.querySelector(LOCATION);
		if (!fields) {
			return;
		}

		// One way to pay is a hidden field; several are radios, one of them ticked.
		const ways   = [...root.querySelectorAll(PROVIDER)];
		const chosen = ways.find((way) => way.type !== 'radio' || way.checked);
		const away   = Boolean(chosen) && chosen.hasAttribute('data-works-out-tax');

		fields.hidden = away;
		fields.querySelectorAll('input, select').forEach((field) => { field.disabled = away; });
	};

	apply();
	if (watched.has(root)) {
		return;
	}
	watched.add(root);

	root.addEventListener('change', (event) => {
		if (event.target instanceof Element && event.target.matches(PROVIDER)) {
			apply();
		}
	});
}

const start = () => {
	initCartDrawer();
	mountEmbeddedCheckout();
	syncCheckoutLocation();
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start);
} else {
	start();
}

// A checkout form posted with HTMX is answered with the mount point in its
// place. HTMX announces a swap on the document when the element that asked
// for it is gone, which here it is.
document.addEventListener('htmx:after:swap', () => {
	mountEmbeddedCheckout();
	// A form that arrived with the swap starts as its chosen way to pay says.
	syncCheckoutLocation();
});
