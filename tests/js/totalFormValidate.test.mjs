import TotalForm from '../../javascript/totalform/totalform.js';

//-----------------------------------------------
// validate() is the submit gate. It must do two things when the form is
// invalid: render the error summary AND put the cursor on the first offending
// field. The summary alone (added in 3.1.7, which dropped the old
// `form.reportValidity()`) leaves the user with feedback appended to the
// bottom of a long form and no idea which field to fix.
//
// TotalForm's constructor takes 25+ dependencies, so these exercise the real
// methods against a real <form> element and stub fields.
//-----------------------------------------------

const stubField = (property, { valid = true, visible = true, sub = false } = {}) => ({
	property,
	label: property,
	validate: () => valid,
	isVisible: () => visible,
	isSubField: () => sub,
	container: document.createElement('div'),
	input: {
		checkValidity: () => valid,
		validationMessage: valid ? '' : 'Please fill out this field.',
	},
});

function formWith(fields) {
	const form = Object.create(TotalForm.prototype);
	form.fields = fields;
	form.form = document.createElement('form');
	// Record scrollToField instead of running it — jsdom has no scrollIntoView
	// and the real method defers focus behind a timeout.
	form.scrolled = [];
	form.scrollToField = field => form.scrolled.push(field);
	return form;
}

describe('TotalForm.validate', () => {
	test('sends the cursor to the FIRST invalid field, not the last', () => {
		const title = stubField('title', { valid: false });
		const body = stubField('body', { valid: false });
		const form = formWith([stubField('slug'), title, body]);

		expect(form.validate()).toBe(false);
		expect(form.scrolled).toEqual([title]);
	});

	test('still renders the summary listing every invalid field', () => {
		const form = formWith([
			stubField('title', { valid: false }),
			stubField('body', { valid: false }),
		]);

		form.validate();

		const links = form.form.querySelectorAll('.tcms-error-summary-link');
		expect(Array.from(links).map(a => a.textContent)).toEqual([
			'title - Please fill out this field.',
			'body - Please fill out this field.',
		]);
	});

	test('ignores hidden fields and sub-fields when choosing where to land', () => {
		const hidden = stubField('hidden', { valid: false, visible: false });
		const sub = stubField('cardname', { valid: false, sub: true });
		const real = stubField('title', { valid: false });
		const form = formWith([hidden, sub, real]);

		form.validate();

		expect(form.scrolled).toEqual([real]);
	});

	test('a valid form clears the summary and scrolls nowhere', () => {
		const form = formWith([stubField('title'), stubField('body')]);

		expect(form.validate()).toBe(true);
		expect(form.scrolled).toEqual([]);
	});
});
