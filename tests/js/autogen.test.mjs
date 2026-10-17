import { readFileSync } from 'node:fs';

import { vi } from 'vite-plus/test';

import Autogen from '../../javascript/totalform/autogen.js';
import TotalField from '../../javascript/totalform/totalfield.js';

//-----------------------------------------------
// Autogen interpolates a pattern like "${title}-${oid-0000}" from form data plus
// special variables (uuid, timestamps, current date, padded oid). It reads only
// `this.field`, so we drive it with a stub field.
//-----------------------------------------------

function makeAutogen(pattern, { fields = {}, count = null } = {}) {
	const formEl = document.createElement('div');
	if (count !== null) formEl.setAttribute('data-collection-count', String(count));
	const field = {
		settings: { autogen: pattern },
		isInDeck: false,
		form: { form: formEl, generateData: () => fields },
	};
	return new Autogen(field);
}

describe('Autogen.getFieldNames', () => {
	test('returns referenced field names, excluding reserved + oid- tokens', () => {
		expect(makeAutogen('${title}-${oid-00000}').getFieldNames()).toEqual(['title']);
		expect(makeAutogen('${first}-${last}').getFieldNames()).toEqual(['first', 'last']);
	});

	test('returns nothing when the pattern is only reserved variables', () => {
		expect(makeAutogen('${currentyear}-${uuid}-${oid}').getFieldNames()).toEqual([]);
	});

	test('excludes sized uid- tokens (they are special variables, not fields)', () => {
		expect(makeAutogen('${title}-${uid-8}').getFieldNames()).toEqual(['title']);
	});
});

describe('Autogen.generate', () => {
	test('interpolates referenced field values', () => {
		expect(makeAutogen('${name}', { fields: { name: 'Hello World' } }).generate()).toBe('Hello World');
		expect(makeAutogen('${a}-${b}', { fields: { a: 'x', b: 'y' } }).generate()).toBe('x-y');
	});

	test('coerces numbers and ignores non-string/number field values', () => {
		const out = makeAutogen('${title}-${count}-${obj}', {
			fields: { title: 'post', count: 5, obj: { nested: true } },
		}).generate();
		// obj is filtered out by collectFormData → interpolates to ''
		expect(out).toBe('post-5-');
	});

	test('pads an oid token with leading zeros from the collection count', () => {
		// count 6 → next oid 7 → padded to width 3
		expect(makeAutogen('${oid-000}', { count: 6 }).generate()).toBe('007');
	});

	test('resolves the current-year special variable', () => {
		const year = String(new Date().getFullYear());
		expect(makeAutogen('${currentyear}', { count: 0 }).generate()).toBe(year);
	});

	test('produces a v4-shaped uuid for the uuid special variable', () => {
		expect(makeAutogen('${uuid}').generate()).toMatch(
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
		);
	});

	test('bare ${uid} produces exactly 7 base-36 chars', () => {
		expect(makeAutogen('${uid}').generate()).toMatch(/^[0-9a-z]{7}$/);
	});

	test('${uid-N} produces exactly N base-36 chars (including lengths beyond one draw)', () => {
		expect(makeAutogen('${uid-12}').generate()).toMatch(/^[0-9a-z]{12}$/);
		expect(makeAutogen('${uid-3}').generate()).toMatch(/^[0-9a-z]{3}$/);
		expect(makeAutogen('${uid-40}').generate()).toMatch(/^[0-9a-z]{40}$/);
	});

	test('${uid-N} composes with other tokens', () => {
		const out = makeAutogen('${title}-${uid-5}', { fields: { title: 'post' } }).generate();
		expect(out).toMatch(/^post-[0-9a-z]{5}$/);
	});
});

//-----------------------------------------------
// The Designer Token is generated from the pattern in the shipped template
// schema. It must carry its full advertised entropy — a pattern that repeats
// one ${uid} draw looks 14 chars long but is only 7 chars of randomness.
//-----------------------------------------------
describe('designer token pattern', () => {
	const schema = JSON.parse(
		readFileSync('resources/schemas/template.json', 'utf8'),
	);
	const pattern = schema.properties.designerToken.settings.autogen;

	test('generates a token that is random across its whole length', () => {
		// Deterministic draws so "both halves are identical" can only mean the
		// pattern reused a single interpolated value.
		const random = vi.spyOn(Math, 'random');
		let n = 0;
		random.mockImplementation(() => ((n++ * 0.0137) % 1));

		const token = makeAutogen(pattern).generate();
		random.mockRestore();

		expect(token).toHaveLength(14);
		expect(token.slice(0, 7)).not.toBe(token.slice(7));
	});
});

describe('Autogen.attachListeners', () => {
	// Reproduces the Auth-collection report: the schema's form order puts the
	// profile `image` field BEFORE the `name` field, so the image's readonly
	// "Filename" sub-input (also name="name") is the first `[name="name"]` in
	// the DOM. The listener must land on the form's own Name field, not on the
	// composite's sub-field, or typing a name never regenerates the id.
	function buildForm() {
		document.body.innerHTML = `
			<form>
				<div class="form-field" data-type="id"><input name="id" value=""></div>
				<div class="form-field" data-type="image">
					<input name="image" type="hidden">
					<details>
						<div class="form-field" data-type="text"><input name="name" value="avatar.jpg" readonly></div>
						<div class="form-field" data-type="number"><input name="width" value="800" readonly></div>
					</details>
				</div>
				<div class="form-field" data-type="text"><input name="name" value=""></div>
			</form>`;
		return document.querySelector('form');
	}

	function makeAutogenIn(formEl, pattern, { isInDeck = false, deckItem = null } = {}) {
		const field = {
			settings: { autogen: pattern },
			isInDeck,
			deckItem,
			form: { form: formEl, generateData: () => ({}) },
		};
		return new Autogen(field);
	}

	// What TotalField.changed() dispatches (see dispatcher.js): bubbling, on the
	// field's own container.
	const fieldChange = (container, type = 'field-change') =>
		container.dispatchEvent(new CustomEvent(type, { bubbles: true }));

	test('listens on the form\'s own field, not a composite sub-field rendered earlier', () => {
		const formEl = buildForm();
		const callback = vi.fn();
		makeAutogenIn(formEl, '${name}').attachListeners(callback);

		const [filenameInput, nameInput] = formEl.querySelectorAll('input[name="name"]');
		expect(filenameInput.hasAttribute('readonly')).toBe(true); // sanity: DOM order is image first

		fieldChange(nameInput.closest('.form-field'));
		expect(callback).toHaveBeenCalledTimes(1);

		// The Filename sub-field's own event bubbles through the image, never the Name field.
		fieldChange(filenameInput.closest('.form-field'), 'subfield-change');
		expect(callback).toHaveBeenCalledTimes(1);
	});

	test('scopes to the deck item and skips its composite sub-fields too', () => {
		document.body.innerHTML = `
			<form>
				<div class="form-field" data-type="text"><input name="name" value="outer"></div>
				<div class="form-field" data-type="deck">
					<div class="deck-item">
						<div class="form-field" data-type="image">
							<input name="photo" type="hidden">
							<div class="form-field" data-type="text"><input name="name" value="pic.jpg" readonly></div>
						</div>
						<div class="form-field" data-type="text"><input name="name" value=""></div>
					</div>
				</div>
			</form>`;
		const formEl = document.querySelector('form');
		const deckItem = document.querySelector('.deck-item');
		const callback = vi.fn();
		makeAutogenIn(formEl, '${name}', { isInDeck: true, deckItem }).attachListeners(callback);

		const [outer, filename, own] = formEl.querySelectorAll('input[name="name"]');
		// Deck item fields are nested in the deck's .form-field, so they emit subfield-change.
		fieldChange(own.closest('.form-field'), 'subfield-change');
		expect(callback).toHaveBeenCalledTimes(1);
		fieldChange(filename.closest('.form-field'), 'subfield-change');
		fieldChange(outer.closest('.form-field'));
		expect(callback).toHaveBeenCalledTimes(1);
	});

	test('follows a field that sets its value in code and never fires a native change', async () => {
		// Tiptap, date, color, list, card, … all do this: input.value = x; this.changed().
		// Before this listened for the TotalField event, `${title}` from one of those never ran.
		vi.useFakeTimers();
		try {
			document.body.innerHTML = `
				<form>
					<div class="form-field" data-type="id"><input name="id" value=""></div>
					<div class="form-field" data-type="styledtext"><textarea name="title"></textarea></div>
				</form>`;
			const formEl = document.querySelector('form');
			const titleField = new TotalField(formEl.querySelector('[data-type="styledtext"]'), {});
			const callback = vi.fn();
			makeAutogenIn(formEl, '${title}').attachListeners(callback);

			titleField.input.value = '<p>Hello</p>';
			titleField.changed();
			vi.advanceTimersByTime(300); // the dispatcher debounce
			expect(callback).toHaveBeenCalledTimes(1);
		} finally {
			vi.useRealTimers();
		}
	});
});
