/**
 * The storefront's own script: the slide-out cart, and the payment form of
 * an on-page checkout.
 *
 * It is small on purpose. Everything the cart shows comes from the server,
 * and each piece of it (the drawer's body, the count badge, the widget)
 * reloads itself through HTMX when the cart changes. All this does is open
 * and close the drawer, and hand a payment provider's module the place the
 * server made for its form.
 */

const TOGGLE = '[data-cart-toggle]';
const CLOSE  = '[data-cart-close]';
const READY  = 'cartDrawerReady';

/**
 * Wire the cart drawer in `root`, if there is one. Safe to call again: a
 * drawer is wired once.
 *
 * @param {Document} root
 */
export function initCartDrawer(root = document) {
	const dialog = root.querySelector('#cart-drawer');
	if (!dialog || dialog.dataset[READY] === 'true') {
		return;
	}
	dialog.dataset[READY] = 'true';

	// What had focus when the drawer opened, to give it back when it closes.
	let opener = null;

	const open = (from) => {
		if (dialog.open) {
			return;
		}
		opener = from;
		dialog.showModal();
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
		if (target.closest(CLOSE) || target === dialog) {
			dialog.close();
		}
	});

	// Esc closes a dialog without going through the code above; `close` is
	// the one event every way of closing ends in.
	dialog.addEventListener('close', () => {
		if (opener instanceof HTMLElement && opener.isConnected) {
			opener.focus();
		}
		opener = null;
	});

	// Every change to the cart is announced on the body (the server sends
	// `HX-Trigger: cart-updated`). Opening on it is how "added to cart" shows.
	root.body.addEventListener('cart-updated', () => {
		if (dialog.dataset.openOnAdd === 'true') {
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

const start = () => {
	initCartDrawer();
	mountEmbeddedCheckout();
};

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start);
} else {
	start();
}

// A checkout form posted with HTMX is answered with the mount point in its
// place. HTMX announces a swap on the document when the element that asked
// for it is gone, which here it is.
document.addEventListener('htmx:after:swap', () => mountEmbeddedCheckout());
