//-----------------------------------------------
// Layers as the Layers tab edits them.
//
// A layer is one ImageWorks mark (MarkLayers): a fill, a text, a photo from
// a record or a logo. The tab shows a curated set of keys for each kind and
// keeps every other key untouched, so a layer written by hand comes back out
// of Apply with its stroke, shadow or angle intact.
//
// Pure functions only: no DOM, so Vitest can cover them.
//-----------------------------------------------

/** ImageWorks draws at most this many layers (MarkLayers::MAX). */
export const MAX_LAYERS = 6;

/** The nine placements, row by row, as the position grid lays them out. */
export const POSITIONS = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'];

/** A fill's fade: none, or the edge it is solid at. */
export const GRADIENTS = ['', 'bottom', 'top', 'left', 'right'];

/** Text alignment inside a wrapped box. */
export const ALIGNMENTS = ['left', 'center', 'right'];

/** The image's own colors, which a fill or a text color can name. */
export const PALETTE = ['palette0', 'palette1', 'palette2', 'palette3', 'palette4'];

/** The keys each kind's controls cover; any other key is "also set in JSON". */
export const EDITED_KEYS = {
	fill  : ['markfill', 'markalpha', 'markgradient', 'markw', 'markh', 'markpos'],
	text  : ['marktext', 'marktextsize', 'marktextw', 'marktextlines', 'marktextcolor', 'marktextalign', 'marktextpos', 'marktextpad', 'marktexty', 'marktextfont', 'marktextbg'],
	image : ['markimage', 'markshape', 'markw', 'markpos', 'markpad'],
	logo  : ['mark', 'markw', 'markalpha', 'markpos', 'markpad'],
};

/**
 * Which kind of layer the tab edits this as. Unlike overlay.js's layerKind()
 * a layer still being built counts: a photo layer with no path yet is a
 * photo layer.
 */
export function editorKind(layer) {
	if (!layer || typeof layer !== 'object') return null;
	if ('markimage' in layer) return 'image';
	if ('markfill' in layer) return 'fill';
	if ('mark' in layer) return 'logo';
	if ('marktext' in layer) return 'text';

	return null;
}

/** A new layer of a kind, ready to draw. A logo starts on the first watermark. */
export function newLayer(kind, { logo = '' } = {}) {
	switch (kind) {
		case 'fill':
			return { markfill: '0b0f1a', markgradient: 'bottom', markh: '100h', markpos: 'bottom', markalpha: 85 };
		case 'text':
			return { marktext: '${socialTitle}', marktextw: '80w', marktextsize: '6w', marktextpos: 'bottom-left', marktextpad: '5w' };
		case 'image':
			return { markimage: '', markshape: 'circle', markw: '15w', markpos: 'top-right', markpad: '4w' };
		case 'logo':
			return { mark: logo, markw: '15w', markpos: 'top-right', markpad: '4w' };
		default:
			return null;
	}
}

/** The keys a layer sets that its kind's controls do not show. */
export function otherKeys(layer) {
	const edited = EDITED_KEYS[editorKind(layer)] ?? [];

	return Object.keys(layer ?? {}).filter(key => !edited.includes(key));
}

/**
 * A size as the controls show it: `7w` is 7 % of the card's width, `30h`
 * 30 % of its height, and a bare number is pixels at 1200×630.
 *
 * @returns {{value: string, unit: 'w'|'h'|'px'}}
 */
export function parseSize(value) {
	if (typeof value === 'number' && Number.isFinite(value)) return { value: String(value), unit: 'px' };

	const match = String(value ?? '').trim().match(/^(\d+(?:\.\d+)?)([wh]?)$/);
	if (!match) return { value: '', unit: 'w' };

	return { value: match[1], unit: match[2] === '' ? 'px' : match[2] };
}

/** The stored form of a size, or null to drop the key. */
export function formatSize(value, unit) {
	const number = String(value ?? '').trim();
	if (number === '' || !/^\d+(?:\.\d+)?$/.test(number)) return null;
	if (unit === 'px') return Math.round(Number(number));

	return `${number}${unit === 'h' ? 'h' : 'w'}`;
}

/**
 * A layer with one key set, or removed when the value is null or ''.
 * Returns a new object; the key keeps its place when it was already set.
 */
export function withKey(layer, key, value) {
	const next = { ...layer };
	if (value === null || value === undefined || value === '') {
		delete next[key];
	} else {
		next[key] = value;
	}

	return next;
}

/** Move a layer from one drawing position to another. */
export function moveLayer(marks, from, to) {
	const next = [...marks];
	if (from < 0 || from >= next.length || to < 0 || to >= next.length) return next;
	const [layer] = next.splice(from, 1);
	next.splice(to, 0, layer);

	return next;
}

/** A hex color for `<input type="color">`: `#rrggbb`, or black for anything else. */
export function colorInputValue(value) {
	const hex = String(value ?? '').trim().replace(/^#/, '').toLowerCase();
	if (/^[0-9a-f]{6}$/.test(hex)) return `#${hex}`;
	if (/^[0-9a-f]{3}$/.test(hex)) return `#${[...hex].map(c => c + c).join('')}`;

	return '#000000';
}

/** The stored form of a picked color: six hex digits, no `#`. */
export function storedColor(value) {
	return String(value ?? '').trim().replace(/^#/, '').toLowerCase();
}

/**
 * A photo layer's path, read back into the three-part builder:
 * `team/${author}/photo` is the collection, the record field that holds the
 * id, and the image property. Anything else is not a builder path.
 *
 * @returns {{collection: string, field: string, image: string}|null}
 */
export function parseImagePath(path) {
	const match = String(path ?? '').match(/^([A-Za-z0-9_-]+)\/\$\{([^}]+)\}\/([A-Za-z0-9_-]+(?:\/[A-Za-z0-9 ._-]+)?)$/);

	return match ? { collection: match[1], field: match[2].trim(), image: match[3] } : null;
}

/** The path the builder writes, or '' until all three parts are chosen. */
export function imagePath({ collection, field, image }) {
	return collection && field && image ? `${collection}/\${${field}}/${image}` : '';
}

/**
 * Text with `${path}` put in place of the selection, and where the cursor
 * goes after it.
 */
export function insertPlaceholder(text, start, end, path) {
	const token  = `\${${path}}`;
	const before = String(text ?? '').slice(0, start);
	const after  = String(text ?? '').slice(end);

	return { text: before + token + after, cursor: before.length + token.length };
}

/**
 * The layers the preview draws: hidden ones are left out (the eye hides a
 * layer from the preview only), and so is a layer still being built, which
 * ImageWorks would refuse.
 */
export function previewMarks(marks, hidden) {
	return marks.filter(layer => !hidden.has(layer) && isComplete(layer));
}

/** Whether a layer has what its kind needs to draw. */
export function isComplete(layer) {
	switch (editorKind(layer)) {
		case 'image':
			return typeof layer.markimage === 'string' && layer.markimage !== '';
		case 'logo':
			return typeof layer.mark === 'string' && layer.mark !== '';
		case 'fill':
			return String(layer.markfill ?? '') !== '';
		case 'text':
			return String(layer.marktext ?? '') !== '';
		default:
			return true;
	}
}
