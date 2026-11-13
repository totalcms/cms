import { vi } from 'vitest';
import { fitOf, layerKind, marksOf, parseOverlay, serializeOverlay, summarize, withFit, withMarks } from '../../javascript/totalform/overlay-designer/overlay.js';
import { LivePreview } from '../../javascript/totalform/overlay-designer/preview-client.js';
import OverlayDesigner from '../../javascript/totalform/overlay-designer/designer.js';
import { MAX_LAYERS, editorKind, formatSize, imagePath, insertPlaceholder, moveLayer, newLayer, otherKeys, parseImagePath, parseSize, previewMarks, withKey } from '../../javascript/totalform/overlay-designer/layers.js';

//-----------------------------------------------
// The share-image overlay designer: the overlay helpers, the live preview's
// stale-answer guard, and the dialog's Apply / Cancel contract with the JSON
// field's textarea.
//-----------------------------------------------

const t = (key, params = {}, fallback = key) => {
	let text = fallback ?? key;
	for (const [name, value] of Object.entries(params)) text = text.replace(`{${name}}`, String(value));
	return text;
};

const FADE  = { markfill: '0b0f1a', markgradient: 'bottom', markh: '100h', markpos: 'bottom', markalpha: 85 };
const TITLE = { marktext: '${socialTitle}', marktextsize: '7w' };

describe('overlay helpers', () => {
	test('an empty field is an empty overlay, not an error', () => {
		expect(parseOverlay('')).toEqual({ ok: true, overlay: {}, error: '' });
		expect(parseOverlay('  ')).toEqual({ ok: true, overlay: {}, error: '' });
	});

	test('JSON that does not parse, or is not an object or list, is not ok', () => {
		expect(parseOverlay('{"marks": [').ok).toBe(false);
		expect(parseOverlay('"text"').ok).toBe(false);
		expect(parseOverlay('null').ok).toBe(false);
	});

	test('reads layers from both stored shapes', () => {
		expect(marksOf({ marks: [FADE, TITLE] })).toEqual([FADE, TITLE]);
		expect(marksOf([FADE])).toEqual([FADE]);
		expect(marksOf({ marks: 'nope' })).toEqual([]);
	});

	test('keeps keys it does not know when layers change', () => {
		const overlay = { fit: 'crop-top', note: 'mine', marks: [{ ...TITLE, marktextstroke: 4 }] };
		const changed = withMarks(overlay, [...marksOf(overlay), FADE]);

		expect(changed.note).toBe('mine');
		expect(changed.fit).toBe('crop-top');
		expect(changed.marks[0].marktextstroke).toBe(4);
	});

	test('a bare list stays a list until it needs a fit', () => {
		expect(withMarks([FADE], [TITLE])).toEqual([TITLE]);
		expect(withFit([FADE], 'crop-top')).toEqual({ fit: 'crop-top', marks: [FADE] });
		expect(fitOf(withFit({ fit: 'crop-top', marks: [] }, ''))).toBe('');
	});

	test('an empty overlay is stored as {}', () => {
		expect(serializeOverlay({})).toBe('{}');
		expect(serializeOverlay([])).toBe('{}');
		expect(serializeOverlay({ marks: [] })).toBe('{}');
		expect(JSON.parse(serializeOverlay({ marks: [TITLE] }))).toEqual({ marks: [TITLE] });
	});

	test('names the four layer kinds', () => {
		expect(layerKind(FADE)).toBe('fill');
		expect(layerKind(TITLE)).toBe('text');
		expect(layerKind({ markimage: 'team/${author}/photo' })).toBe('image');
		expect(layerKind({ mark: 'logo.png' })).toBe('logo');
		expect(layerKind({ markalpha: 50 })).toBeNull();
	});

	test('summarizes an overlay for the form row', () => {
		expect(summarize({}, t)).toBe('No overlay. Shares use the plain image.');
		expect(summarize({ marks: [FADE, TITLE, { marktext: '${site}' }] }, t)).toBe('3 layers · fade, title, site name');
		expect(summarize([{ marktext: 'By ${author}' }], t)).toBe('1 layer · author');
		expect(summarize([{ marktext: 'Read the whole story today' }], t)).toBe('1 layer · “Read the whole…”');
	});
});

describe('layer helpers', () => {
	test('reads and writes sizes in card units and pixels', () => {
		expect(parseSize('7w')).toEqual({ value: '7', unit: 'w' });
		expect(parseSize('32.5h')).toEqual({ value: '32.5', unit: 'h' });
		expect(parseSize(40)).toEqual({ value: '40', unit: 'px' });
		expect(parseSize('nonsense')).toEqual({ value: '', unit: 'w' });

		expect(formatSize('7', 'w')).toBe('7w');
		expect(formatSize('30', 'h')).toBe('30h');
		expect(formatSize('40.4', 'px')).toBe(40);
		expect(formatSize('', 'w')).toBeNull();
	});

	test('a key set to nothing is removed, and the rest of the layer kept', () => {
		const layer = { ...TITLE, marktextstroke: 4 };

		expect(withKey(layer, 'marktextsize', null)).toEqual({ marktext: '${socialTitle}', marktextstroke: 4 });
		expect(withKey(layer, 'marktextcolor', 'ff0000').marktextstroke).toBe(4);
	});

	test('lists the keys a layer sets that its controls do not show', () => {
		expect(otherKeys({ ...TITLE, marktextstroke: 4, marktextangle: 10 })).toEqual(['marktextstroke', 'marktextangle']);
		expect(otherKeys(FADE)).toEqual([]);
	});

	test('a photo layer still being built is a photo layer, but not drawn', () => {
		const photo = newLayer('image');

		expect(editorKind(photo)).toBe('image');
		expect(previewMarks([FADE, photo], new Set())).toEqual([FADE]);
	});

	test('the preview leaves out hidden layers', () => {
		expect(previewMarks([FADE, TITLE], new Set([FADE]))).toEqual([TITLE]);
	});

	test('reads a photo path back into the builder, and writes it', () => {
		expect(parseImagePath('team/${author}/photo')).toEqual({ collection: 'team', field: 'author', image: 'photo' });
		expect(parseImagePath('team/jane/photo')).toBeNull();
		expect(imagePath({ collection: 'team', field: 'author', image: 'gallery/first' })).toBe('team/${author}/gallery/first');
		expect(imagePath({ collection: 'team', field: '', image: 'photo' })).toBe('');
	});

	test('inserts a placeholder over the selection', () => {
		expect(insertPlaceholder('By ', 3, 3, 'author')).toEqual({ text: 'By ${author}', cursor: 12 });
		expect(insertPlaceholder('Hello NAME!', 6, 10, 'title')).toEqual({ text: 'Hello ${title}!', cursor: 14 });
	});

	test('moves a layer in drawing order', () => {
		expect(moveLayer(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
		expect(moveLayer(['a', 'b'], 0, 5)).toEqual(['a', 'b']);
	});
});

describe('LivePreview', () => {
	test('drops an answer that arrives after a newer request', async () => {
		const resolvers = [];
		const results   = [];
		const preview   = new LivePreview(result => results.push(result), {
			request: () => new Promise(resolve => resolvers.push(resolve)),
		});

		const first  = preview.now({ id: 'a' });
		const second = preview.now({ id: 'b' });
		resolvers[1]({ reason: 'second' });
		resolvers[0]({ reason: 'first' });
		await Promise.all([first, second]);

		expect(results).toEqual([{ reason: 'second' }]);
	});

	test('debounces scheduled requests into one', async () => {
		vi.useFakeTimers();
		const request = vi.fn(async () => ({ reason: 'drawn' }));
		const preview = new LivePreview(() => {}, { delay: 400, request });

		preview.schedule({ id: 'a' });
		preview.schedule({ id: 'b' });
		await vi.advanceTimersByTimeAsync(400);
		vi.useRealTimers();

		expect(request).toHaveBeenCalledTimes(1);
		expect(request.mock.calls[0][0]).toEqual({ id: 'b' });
	});
});

describe('OverlayDesigner', () => {
	const stored = JSON.stringify({ marks: [FADE, TITLE] });

	function build(value) {
		document.body.innerHTML = '<form><div class="form-field" data-type="json"><textarea name="socialImageOptions"></textarea></div></form>';
		const input = document.querySelector('textarea');
		input.value = value;

		const field = {
			input,
			container : input.closest('.form-field'),
			settings  : { designer: 'socialOverlay' },
			label     : 'Social Image Overlay',
			changed   : vi.fn(),
		};

		return { field, designer: new OverlayDesigner(field) };
	}

	beforeAll(() => {
		HTMLDialogElement.prototype.showModal = function () { this.open = true; };
		HTMLDialogElement.prototype.close     = function () { this.open = false; };
		window.IntersectionObserver = class { observe() {} disconnect() {} };
	});

	beforeEach(() => {
		globalThis.fetch = vi.fn(async url => ({
			ok   : true,
			json : async () => (String(url).includes('options')
				? { layers: true, collections: [{ id: 'blog', name: 'Blog' }], collection: 'blog', objects: [{ id: 'hello', title: 'Hello', draft: false }], default: 'hello', ownOverlay: false, presets: [], fields: [{ path: 'title', label: 'Title' }, { path: 'seo.title', label: 'SEO → Title' }], logos: ['logo.png'], fonts: [], imageSources: [{ id: 'team', name: 'Team', images: [{ path: 'photo', label: 'Photo' }] }] }
				: { reason: 'drawn', message: 'Drawn', layer: null, asIs: false, image: 'data:image/jpeg;base64,AA==' }),
		}));
	});

	test('shows a one-line summary and a Design button in place of the editor', () => {
		const { field } = build(stored);
		const row       = field.container.querySelector('.overlay-row');

		expect(row.querySelector('.overlay-row-summary').textContent).toBe('2 layers · fade, title');
		expect(row.querySelector('button').textContent).toBe('Design…');
	});

	test('offers to add an overlay when there is none', () => {
		const { field } = build('');

		expect(field.container.querySelector('.overlay-row button').textContent).toBe('Add overlay…');
	});

	test('Cancel leaves the field untouched', async () => {
		const { field, designer } = build(stored);
		await designer.open();

		designer.working = withMarks(designer.working, []);
		document.querySelector('.overlay-actions .dash-button.transparent').click();

		expect(field.input.value).toBe(stored);
		expect(field.changed).not.toHaveBeenCalled();
	});

	test('Apply writes the working copy and marks the field changed', async () => {
		const { field, designer } = build(stored);
		await designer.open();

		designer.working = withMarks(designer.working, [TITLE]);
		designer.applyButton.click();

		expect(JSON.parse(field.input.value)).toEqual({ marks: [TITLE] });
		expect(field.changed).toHaveBeenCalledTimes(1);
		expect(field.container.querySelector('.overlay-row-summary').textContent).toBe('1 layer · title');
	});

	test('Remove overlay then Apply stores {}', async () => {
		const { field, designer } = build(stored);
		await designer.open();

		document.querySelector('.overlay-remove').click();
		designer.applyButton.click();

		expect(field.input.value).toBe('{}');
	});

	test('JSON that does not parse disables Apply and opens on the JSON tab', async () => {
		const { designer } = build('{"marks": [');
		await designer.open();

		expect(designer.applyButton.disabled).toBe(true);
		expect(designer.activeTab).toBe('json');
	});

	test('opens on the Layers tab, top layer first', async () => {
		const { designer } = build(stored);
		await designer.open();

		const names = [...document.querySelectorAll('.overlay-layer-name')].map(node => node.textContent);
		expect(designer.activeTab).toBe('layers');
		expect(names).toEqual(['title', 'fade']);
	});

	test('editing a layer keeps the keys it does not show, through Apply', async () => {
		const { field, designer } = build(JSON.stringify({ marks: [{ ...TITLE, marktextstroke: 4 }] }));
		await designer.open();

		const text = document.querySelector('.overlay-text');
		text.value = 'By ${author}';
		text.dispatchEvent(new Event('input'));
		designer.applyButton.click();

		expect(JSON.parse(field.input.value)).toEqual({ marks: [{ marktext: 'By ${author}', marktextsize: '7w', marktextstroke: 4 }] });
		expect(document.querySelector('.overlay-layer-other').textContent).toContain('marktextstroke');
	});

	test('the Insert field menu puts a placeholder at the cursor', async () => {
		const { designer } = build(JSON.stringify({ marks: [{ marktext: 'Hi ' }] }));
		await designer.open();

		const text = document.querySelector('.overlay-text');
		text.setSelectionRange(3, 3);
		const menu = document.querySelector('.overlay-insert');
		expect([...menu.querySelectorAll('option')].map(o => o.value)).toContain('seo.title');

		menu.value = 'seo.title';
		menu.dispatchEvent(new Event('change'));

		expect(marksOf(designer.working)[0].marktext).toBe('Hi ${seo.title}');
		expect(menu.value).toBe('');
	});

	test('a new fill goes underneath, anything else on top', async () => {
		const { designer } = build(JSON.stringify({ marks: [TITLE] }));
		await designer.open();

		const add = document.querySelector('.overlay-layer-add');
		add.value = 'fill';
		add.dispatchEvent(new Event('change'));
		add.value = 'logo';
		add.dispatchEvent(new Event('change'));

		expect(marksOf(designer.working).map(editorKind)).toEqual(['fill', 'text', 'logo']);
		expect(marksOf(designer.working)[2].mark).toBe('logo.png');
	});

	test('stops adding at six layers', async () => {
		const { designer } = build(JSON.stringify({ marks: Array.from({ length: MAX_LAYERS }, () => TITLE) }));
		await designer.open();

		expect(document.querySelector('.overlay-layer-add').disabled).toBe(true);
	});

	test('a hidden layer leaves the preview but stays in Apply', async () => {
		const { field, designer } = build(stored);
		await designer.open();

		// The fade is the bottom row; hide it.
		const rows = document.querySelectorAll('.overlay-layer');
		rows[1].querySelector('.overlay-layer-eye').click();

		expect(designer.previewBody().overlay.marks).toEqual([TITLE]);
		designer.applyButton.click();
		expect(JSON.parse(field.input.value).marks).toEqual([FADE, TITLE]);
	});

	test('deleting a layer removes it from the working copy', async () => {
		const { designer } = build(stored);
		await designer.open();

		document.querySelector('.overlay-layer .overlay-layer-delete').click();

		expect(marksOf(designer.working)).toEqual([FADE]);
	});

	test('the photo builder writes collection/${field}/image', async () => {
		const { designer } = build(JSON.stringify({ marks: [newLayer('image')] }));
		await designer.open();

		const [field, collection] = document.querySelectorAll('.overlay-photo select');
		field.value = 'title';
		field.dispatchEvent(new Event('change'));
		collection.value = 'team';
		collection.dispatchEvent(new Event('change'));

		expect(marksOf(designer.working)[0].markimage).toBe('team/${title}/photo');
	});
});
