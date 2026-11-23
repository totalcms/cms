/**
 * Stripe Checkout on the store's own page.
 *
 * The store's script (commerce.js) imports this module and calls
 * `mount(element, config)` where the server asked for the payment form. The
 * config is made by the Stripe provider for one checkout:
 *
 *   publishableKey  the store's publishable key (public by design)
 *   clientSecret    this one checkout's client secret
 *   scriptUrl       Stripe's own script, for the API version the store uses
 *
 * Stripe's script is always loaded from js.stripe.com, as Stripe requires,
 * and never from anywhere else, whatever the config says.
 */

const STRIPE_ORIGIN = 'https://js.stripe.com';

/** One load per script tag: a second mount waits for the first one's script. */
const loading = new WeakMap();

/**
 * @param {string} url
 * @returns {Promise<Function>} Stripe's `Stripe()` once its script has run
 */
function loadStripe(url) {
	if (new URL(url, document.baseURI).origin !== STRIPE_ORIGIN) {
		return Promise.reject(new Error(`Stripe's script is only loaded from js.stripe.com, not ${url}`));
	}
	// The site may already have Stripe on the page for something of its own.
	if (typeof window.Stripe === 'function') {
		return Promise.resolve(window.Stripe);
	}

	const existing = [...document.scripts].find((script) => loading.has(script));
	if (existing) {
		return loading.get(existing);
	}

	const tag   = document.createElement('script');
	const ready = new Promise((resolve, reject) => {
		tag.addEventListener('load', () => {
			if (typeof window.Stripe === 'function') {
				resolve(window.Stripe);
			} else {
				reject(new Error("Stripe's script loaded without Stripe in it."));
			}
		});
		tag.addEventListener('error', () => {
			// Taken out again so that a later try starts clean.
			tag.remove();
			reject(new Error("Stripe's script could not be loaded."));
		});
	});
	tag.src = url;
	loading.set(tag, ready);
	document.head.append(tag);

	return ready;
}

/**
 * Draw Stripe's checkout in `el`.
 *
 * @param {Element} el
 * @param {{publishableKey: string, clientSecret: string, scriptUrl: string}} config
 */
export async function mount(el, config) {
	const Stripe   = await loadStripe(config.scriptUrl);
	const checkout = await Stripe(config.publishableKey).createEmbeddedCheckoutPage({
		// The secret is already here: the store made the session before it
		// printed this page, so there is nothing to fetch.
		fetchClientSecret: async () => config.clientSecret,
	});

	checkout.mount(el);
}
