import TotalForm from '../../javascript/totalform/totalform.js';
import TotalField from '../../javascript/totalform/totalfield.js';
import { coreFieldTypes } from '../../javascript/totalform/field-types-core.js';
import { allFieldTypes } from '../../javascript/totalform/field-types-all.js';
import { lazyFieldTypes } from '../../javascript/totalform/field-types-lazy.js';

//-----------------------------------------------
// Field classes reach TotalForm through a registry the entry point fills.
// admin.js registers every class statically, so the dashboard builds every
// field synchronously as it always has. forms.js registers the light classes
// and a loader for each heavy one, so a public page downloads Tiptap or
// Dropzone only when a form on it needs them — and a form with a loading
// field lets save() wait for it.
//-----------------------------------------------

function formWith(html, { api = '/api' } = {}) {
	document.body.innerHTML = `<form class="totalform" data-api="${api}" data-route="/collections/things" data-method="POST" data-form="object" data-collection="things">${html}</form>`;
	return document.body.querySelector('form');
}

const textField = (name, value = '') => `<div class="form-field text-field" data-type="text"><input name="${name}" value="${value}"></div>`;
const shoutField = (name, value = '') => `<div class="form-field" data-type="shout"><input name="${name}" value="${value}"></div>`;

class ShoutField extends TotalField {
	getValue() {
		return this.input.value.toUpperCase();
	}
}

beforeEach(() => {
	TotalForm.registerBuiltInFieldTypes({});
	for (const key of Object.keys(TotalForm.fieldTypes)) delete TotalForm.fieldTypes[key];
	vi.restoreAllMocks();
});

describe('the registries', () => {
	test('the core set is what a public form is made of, and none of it pulls a heavy library', () => {
		for (const type of ['id', 'slug', 'text', 'email', 'url', 'hidden', 'phone', 'time', 'textarea', 'number', 'select', 'checkbox', 'toggle', 'radio', 'date', 'datetime', 'color', 'password', 'secret']) {
			expect(coreFieldTypes[type], type).toBeTypeOf('function');
		}
		for (const heavy of ['price', 'styledtext', 'image', 'file', 'gallery', 'code', 'svg', 'deck', 'card', 'list', 'depot']) {
			expect(coreFieldTypes[heavy], heavy).toBeUndefined();
		}
	});

	test('the full set covers every type the admin can render, and the lazy set covers exactly what core does not', () => {
		const everything = ['id', 'slug', 'text', 'time', 'url', 'hidden', 'email', 'phone', 'textarea', 'checkbox', 'toggle', 'checklist', 'multicheckbox', 'radio', 'number', 'price', 'color', 'date', 'datetime', 'select', 'multiselect', 'list', 'password', 'secret', 'range', 'styledtext', 'localizedtext', 'localizedtextarea', 'localizedstyledtext', 'svg', 'image', 'gallery', 'json', 'file', 'depot', 'depotDrop', 'code', 'card', 'video', 'deck', 'deckTable', 'properties', 'customProperties', 'schemaProperties'];

		for (const type of everything) {
			expect(allFieldTypes[type], type).toBeTypeOf('function');
		}
		expect(new Set([...Object.keys(coreFieldTypes), ...Object.keys(lazyFieldTypes)])).toEqual(new Set(everything));
		for (const type of Object.keys(lazyFieldTypes)) {
			expect(coreFieldTypes[type], `${type} is both core and lazy`).toBeUndefined();
			expect(lazyFieldTypes[type].lazy, type).toBeTypeOf('function');
		}
	});
});

describe('building fields from the registry', () => {
	test('a registered class is built synchronously, as the admin always did', () => {
		TotalForm.registerBuiltInFieldTypes({ text: TotalField, shout: ShoutField });
		const form = new TotalForm(formWith(textField('title', 'Hi') + shoutField('mood', 'calm')));

		expect(form.fields.map((f) => f.constructor.name)).toEqual(['TotalField', 'ShoutField']);
		expect(form.pending.size).toBe(0);
		expect(form.generateData()).toEqual({ title: 'Hi', mood: 'CALM' });
	});

	test('a type nobody registered falls back to TotalField with a warning', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		const form = new TotalForm(formWith(shoutField('mood', 'calm')));

		expect(form.fields[0]).toBeInstanceOf(TotalField);
		expect(form.fields[0]).not.toBeInstanceOf(ShoutField);
		expect(warn).toHaveBeenCalledWith('Unknown field', expect.anything());
	});

	test('the built-in registry wins over an extension registration of the same name', () => {
		TotalForm.registerBuiltInFieldTypes({ text: TotalField });
		TotalForm.registerFieldType('text', ShoutField);
		const form = new TotalForm(formWith(textField('title', 'hi')));

		expect(form.fields[0]).not.toBeInstanceOf(ShoutField);
	});
});

describe('a lazily loaded field', () => {
	const lazyShout = { lazy: () => Promise.resolve({ default: ShoutField }) };

	test('is absent until its module arrives, then joins the form', async () => {
		TotalForm.registerBuiltInFieldTypes({ text: TotalField, shout: lazyShout });
		const el = formWith(textField('title', 'Hi') + shoutField('mood', 'calm'));
		const form = new TotalForm(el);

		expect(form.fields.map((f) => f.property)).toEqual(['title']);
		expect(form.pending.size).toBe(1);

		await form.whenReady();

		expect(form.pending.size).toBe(0);
		expect(form.fields.map((f) => f.property)).toEqual(['title', 'mood']);
		expect(form.fields[1]).toBeInstanceOf(ShoutField);
		expect(el.querySelector('[data-type="shout"]').totalfield).toBe(form.fields[1]);
	});

	test('loads each module once however many fields use it', async () => {
		const loader = vi.fn(() => Promise.resolve({ default: ShoutField }));
		TotalForm.registerBuiltInFieldTypes({ shout: { lazy: loader } });
		const form = new TotalForm(formWith(shoutField('a') + shoutField('b') + shoutField('c')));

		await form.whenReady();

		expect(loader).toHaveBeenCalledTimes(1);
		expect(form.fields).toHaveLength(3);
	});

	test('save() waits for the loading field, so its value is in the payload', async () => {
		TotalForm.registerBuiltInFieldTypes({ text: TotalField, shout: lazyShout });
		const form = new TotalForm(formWith(textField('title', 'Hi') + shoutField('mood', 'calm')));
		form.api = { postAPI: vi.fn().mockResolvedValue({ data: { id: 'x' } }) };
		form.closeDialog = vi.fn();

		await form.save();

		expect(form.api.postAPI).toHaveBeenCalledWith('/collections/things', { title: 'Hi', mood: 'CALM' }, 'POST');
	});

	test('announces totalform:loaded once every field is in', async () => {
		TotalForm.registerBuiltInFieldTypes({ shout: lazyShout });
		const el = formWith(shoutField('mood'));
		const loaded = vi.fn();
		el.addEventListener('totalform:loaded', loaded);
		const form = new TotalForm(el);

		expect(loaded).not.toHaveBeenCalled();
		await form.whenReady();
		expect(loaded).toHaveBeenCalledTimes(1);
	});

	test('a module that fails to load is reported and the form still works without it', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		TotalForm.registerBuiltInFieldTypes({ text: TotalField, shout: { lazy: () => Promise.reject(new Error('offline')) } });
		const form = new TotalForm(formWith(textField('title', 'Hi') + shoutField('mood')));

		await form.whenReady();

		expect(form.fields.map((f) => f.property)).toEqual(['title']);
		expect(warn).toHaveBeenCalledWith(expect.stringContaining('shout'), expect.anything());
		expect(form.generateData()).toEqual({ title: 'Hi' });
	});

	test('a re-scan while a field is still loading neither duplicates nor drops it', async () => {
		TotalForm.registerBuiltInFieldTypes({ text: TotalField, shout: lazyShout });
		const form = new TotalForm(formWith(textField('title') + shoutField('mood')));

		form.refreshFields();
		expect(form.pending.size).toBe(1);
		await form.whenReady();

		expect(form.fields.map((f) => f.property)).toEqual(['title', 'mood']);
	});
});
