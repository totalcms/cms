import TotalForm from '../../javascript/totalform/totalform.js';
import PropertyField from '../../javascript/totalform/property.js';

//-----------------------------------------------
// A JSON field holding invalid JSON (e.g. `{"pattern": "^\d+$"}` — `\d` is not
// a legal JSON escape) throws out of JSONField.getValue(). That throw travels
// up through PropertyField.getValue() and PropertiesField.getValue() into
// TotalForm.generateData(), which save() calls as an *argument* to postAPI():
//
//   json.js:78 -> property.js:79 -> properties.js:129 -> totalform.js:1091
//
// Nothing on that path validates first — a composite inherits
// TotalField.validate(), which only checks its own hidden marker input, and
// PropertyField is not a TotalField at all. So the throw escapes save() before
// postAPI() is reached and before .catch() is attached, leaving the form stuck
// in the "processing" state that isProcessing() uses to block every later
// submit. The form is dead until reload, and no request is ever sent.
//-----------------------------------------------

// save() reads only these members, so stubs are enough — TotalForm's
// constructor takes 25+ dependencies (see totalForm.test.mjs).
function formWithFields(fields) {
	const form = Object.create(TotalForm.prototype);

	form.fields    = fields;
	form.form      = { contains: () => true };
	form.route     = '/schemas/blog';
	form.method    = 'PUT';
	form.validated = false;
	form.state     = null;
	form.errors    = [];
	form.posted    = null;
	form.dialogClosed = false;

	form.validate    = () => true;
	form.isError     = () => false;
	form.closeDialog = () => { form.dialogClosed = true; };
	form.processing  = () => { form.state = 'processing'; };
	form.afterSave   = () => {};
	form.error       = error => {
		// TotalForm.error() dispatches the "error" event BEFORE resetting
		// validated, and TotalFormManager branches on that flag to decide
		// between "show this message" and "silently reset the banner"
		// (totalform-manager.js:60). Capture it at dispatch time.
		form.validatedAtError = form.validated;
		form.errors.push(String(error?.message ?? error));
		form.state     = 'error';
		form.validated = false;
	};
	form.api = {
		postAPI: (route, data, method) => {
			form.posted = { route, data, method };
			return Promise.resolve({});
		},
	};

	return form;
}

const field = ({ property, value, throws = null }) => ({
	property,
	isSubField: () => false,
	getValue: () => {
		if (throws) throw throws;
		return value;
	},
});

const badJson = () => {
	try {
		JSON.parse(String.raw`{"pattern": "^\d+\.\d+\.\d+$"}`);
	} catch (e) {
		return e;
	}
	throw new Error('expected invalid JSON to throw');
};

describe('TotalForm.save with a field that throws while collecting its value', () => {
	test('reports the error instead of hanging', () => {
		const form = formWithFields([field({ property: 'properties', throws: badJson() })]);

		expect(() => form.save()).not.toThrow();
		expect(form.errors.join(' ')).toMatch(/Bad escaped character|JSON/i);
	});

	test('does not leave the form locked in the processing state', () => {
		// isProcessing() short-circuits every later submit (totalform.js:431),
		// so a stuck "processing" is a dead form, not just a stuck spinner.
		const form = formWithFields([field({ property: 'properties', throws: badJson() })]);

		form.save();

		expect(form.state).not.toBe('processing');
	});

	test('sends no request', () => {
		const form = formWithFields([field({ property: 'properties', throws: badJson() })]);

		form.save();

		expect(form.posted).toBeNull();
	});

	test('leaves the dialog open so the offending property stays reachable', () => {
		const form = formWithFields([field({ property: 'properties', throws: badJson() })]);

		form.save();

		expect(form.dialogClosed).toBe(false);
	});

	test('still counts as validated so the manager shows the message', () => {
		// TotalFormManager only surfaces the text when form.validated is true;
		// the false branch resets the banner and says nothing.
		const form = formWithFields([field({ property: 'properties', throws: badJson() })]);

		form.save();

		expect(form.validatedAtError).toBe(true);
	});

	test('a form with no throwing field still posts normally', () => {
		const form = formWithFields([field({ property: 'title', value: 'Hello' })]);

		form.save();

		expect(form.posted).toEqual({
			route: '/schemas/blog',
			data: { title: 'Hello' },
			method: 'PUT',
		});
	});
});

describe('PropertyField.getValue error context', () => {
	// The bare parser message ("Bad escaped character in JSON at position 15")
	// says nothing about WHICH property's dialog to open. The schema editor
	// renders one collapsed dialog per property, so the name is the difference
	// between a fixable error and a hunt.
	function propertyFieldWith(fields) {
		const container = document.createElement('div');
		fields.forEach(({ property, value, throws }) => {
			const el = document.createElement('div');
			el.className  = 'form-field';
			el.totalfield = {
				property,
				getValue: () => {
					if (throws) throw throws;
					return value;
				},
			};
			container.appendChild(el);
		});

		const instance = Object.create(PropertyField.prototype);
		instance.container  = container;
		instance.fieldClass = 'schema-field';
		return instance;
	}

	test('names the offending field in the thrown message', () => {
		const property = propertyFieldWith([
			{ property: 'name', value: 'version' },
			{ property: 'extra', throws: badJson() },
		]);

		expect(() => property.getValue()).toThrow(/extra/);
	});

	test('keeps the underlying parser message', () => {
		const property = propertyFieldWith([{ property: 'extra', throws: badJson() }]);

		expect(() => property.getValue()).toThrow(/Bad escaped character/);
	});

	test('collects normally when nothing throws', () => {
		const property = propertyFieldWith([
			{ property: 'name', value: 'version' },
			{ property: 'field', value: 'text' },
		]);

		expect(property.getValue()).toEqual({ name: 'version', field: 'text' });
	});
});
