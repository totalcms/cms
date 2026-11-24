import { syncCheckoutLocation } from '../../javascript/commerce.js';

//-----------------------------------------------
// The checkout form's "Where you are" fields. The store asks them so it
// can work out tax from its own rates. A way to pay that works the tax out
// itself asks the buyer on its own page, so while one is chosen the fields
// are put away, and are not sent or required.
//-----------------------------------------------

/** A checkout form with one way to pay of each kind; `chosen` is the one ticked. */
function form(chosen = 'stripe') {
	document.body.innerHTML = `
		<form id="commerce-checkout-form">
			<fieldset id="where" data-commerce-location>
				<input id="country" name="billingAddress[country]" required>
				<input id="region" name="billingAddress[region]">
			</fieldset>
			<label><input type="radio" id="stripe" name="provider" value="stripe" data-works-out-tax ${chosen === 'stripe' ? 'checked' : ''}> Stripe</label>
			<label><input type="radio" id="paypal" name="provider" value="paypal" ${chosen === 'paypal' ? 'checked' : ''}> PayPal</label>
		</form>
	`;
	syncCheckoutLocation(document);

	return { where: document.getElementById('where'), country: document.getElementById('country'), region: document.getElementById('region') };
}

function choose(id) {
	const radio = document.getElementById(id);
	radio.checked = true;
	radio.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('checkout form: where the buyer is', () => {
	test('is put away while a way to pay that works out the tax is chosen', () => {
		const { where, country, region } = form('stripe');

		expect(where.hidden).toBe(true);
		// A disabled field is not sent, and is not required.
		expect(country.disabled).toBe(true);
		expect(region.disabled).toBe(true);
	});

	test('is there while one that does not is chosen', () => {
		const { where, country } = form('paypal');

		expect(where.hidden).toBe(false);
		expect(country.disabled).toBe(false);
	});

	test('follows the buyer changing their mind, both ways', () => {
		const { where, country } = form('stripe');

		choose('paypal');
		expect(where.hidden).toBe(false);
		expect(country.disabled).toBe(false);

		choose('stripe');
		expect(where.hidden).toBe(true);
		expect(country.disabled).toBe(true);
	});

	test('works for a form with one way to pay, named in a hidden field', () => {
		document.body.innerHTML = `
			<form id="commerce-checkout-form">
				<fieldset id="where" data-commerce-location><input id="country" name="billingAddress[country]" required></fieldset>
				<input type="hidden" name="provider" value="stripe" data-works-out-tax>
			</form>
		`;
		syncCheckoutLocation(document);

		expect(document.getElementById('where').hidden).toBe(true);
	});

	test('leaves the fields alone when no way to pay is chosen yet', () => {
		document.body.innerHTML = `
			<form id="commerce-checkout-form">
				<fieldset id="where" data-commerce-location><input id="country" name="billingAddress[country]" required></fieldset>
				<label><input type="radio" name="provider" value="stripe" data-works-out-tax> Stripe</label>
			</form>
		`;
		syncCheckoutLocation(document);

		expect(document.getElementById('where').hidden).toBe(false);
	});

	test('does nothing on a page with no such fields', () => {
		document.body.innerHTML = '<form id="commerce-checkout-form"><input type="hidden" name="provider" value="stripe" data-works-out-tax></form>';

		expect(() => syncCheckoutLocation(document)).not.toThrow();
	});

	test('finds the form that is on the page now, after the page swapped it', () => {
		form('stripe');
		// HTMX replaces the body on Back and Forward without running the script again.
		const { where } = (() => {
			document.body.innerHTML = document.body.innerHTML.replace('id="stripe" name="provider" value="stripe" data-works-out-tax checked=""', 'id="stripe" name="provider" value="stripe" data-works-out-tax');

			return { where: document.getElementById('where') };
		})();
		choose('paypal');

		expect(where.hidden).toBe(false);
	});
});
