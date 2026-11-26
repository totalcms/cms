import { mount } from '../../resources/extensions/totalcms/stripe/assets/embed.js';

//-----------------------------------------------
// The Stripe extension's module for on-page checkout. commerce.js imports
// it and calls mount(el, config). It loads Stripe's own script (which may
// only come from js.stripe.com), then asks Stripe to draw its checkout in
// the element. Stripe is a stub here: jsdom loads no scripts, so each test
// says when the script "arrives".
//-----------------------------------------------

const CONFIG = { publishableKey: 'pk_test_123', clientSecret: 'cs_test_secret', scriptUrl: 'https://js.stripe.com/endive/stripe.js' };

let checkout;
let stripe;

/** Stripe's script has loaded: the tag says so, and `Stripe` exists. */
function arrive() {
	window.Stripe = vi.fn(() => stripe);
	document.querySelector('script[src^="https://js.stripe.com/"]').dispatchEvent(new Event('load'));
}

beforeEach(() => {
	document.head.innerHTML = '';
	document.body.innerHTML = '<div id="commerce-checkout"></div>';
	delete window.Stripe;
	checkout = { mount: vi.fn() };
	stripe   = { createEmbeddedCheckoutPage: vi.fn(async () => checkout) };
});

describe('stripe embed', () => {
	test('asks Stripe for an embedded page with the client secret, and mounts it on the element', async () => {
		const el      = document.getElementById('commerce-checkout');
		const mounted = mount(el, CONFIG);
		arrive();
		await mounted;

		expect(window.Stripe).toHaveBeenCalledWith('pk_test_123');
		expect(stripe.createEmbeddedCheckoutPage).toHaveBeenCalledTimes(1);
		await expect(stripe.createEmbeddedCheckoutPage.mock.calls[0][0].fetchClientSecret()).resolves.toBe('cs_test_secret');
		expect(checkout.mount).toHaveBeenCalledWith(el);
	});

	test('adds the script tag once across two calls', async () => {
		const el     = document.getElementById('commerce-checkout');
		const first  = mount(el, CONFIG);
		const second = mount(el, CONFIG);
		arrive();
		await Promise.all([first, second]);
		await mount(el, CONFIG);

		expect(document.querySelectorAll('script[src="https://js.stripe.com/endive/stripe.js"]')).toHaveLength(1);
	});

	test('loads Stripe only from js.stripe.com', async () => {
		const el = document.getElementById('commerce-checkout');

		await expect(mount(el, { ...CONFIG, scriptUrl: 'https://js.stripe.com.elsewhere.example/stripe.js' })).rejects.toThrow('js.stripe.com');
		expect(document.querySelectorAll('script')).toHaveLength(0);
	});

	test('fails when the script cannot be loaded, and can be tried again', async () => {
		const el      = document.getElementById('commerce-checkout');
		const mounted = mount(el, CONFIG);
		document.querySelector('script').dispatchEvent(new Event('error'));

		await expect(mounted).rejects.toThrow('could not be loaded');
		expect(document.querySelectorAll('script')).toHaveLength(0);

		const again = mount(el, CONFIG);
		arrive();
		await again;
		expect(checkout.mount).toHaveBeenCalledWith(el);
	});
});
