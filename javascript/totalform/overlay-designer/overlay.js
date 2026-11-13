//-----------------------------------------------
// Share image overlays as the designer sees them.
//
// An overlay is stored as `{fit, marks: [...]}` or as the bare list of marks
// (SocialImageBuilder::normalized). The designer edits whichever shape it was
// given and keeps every key it does not touch, so JSON written by hand or by
// an AI agent comes back out of Apply unchanged.
//
// Pure functions only: no DOM, no fetch, so Vitest can cover them.
//-----------------------------------------------

/**
 * Read the field's value.
 *
 * @param {string} text the JSON in the field
 * @returns {{ok: boolean, overlay: object|Array|null, error: string}}
 *   `ok` is false for text that does not parse, or parses to something that
 *   is neither an object nor a list (the designer opens on the JSON tab).
 */
export function parseOverlay(text) {
	const trimmed = (text ?? '').trim();
	if (trimmed === '') return { ok: true, overlay: {}, error: '' };

	let value;
	try {
		value = JSON.parse(trimmed);
	} catch (error) {
		return { ok: false, overlay: null, error: error.message };
	}

	if (value === null || typeof value !== 'object') {
		return { ok: false, overlay: null, error: '' };
	}

	return { ok: true, overlay: value, error: '' };
}

/** The layers of an overlay, in drawing order (first is drawn first, underneath). */
export function marksOf(overlay) {
	const marks = Array.isArray(overlay) ? overlay : overlay?.marks;

	return Array.isArray(marks) ? marks.filter(layer => layer && typeof layer === 'object' && !Array.isArray(layer)) : [];
}

/** The overlay's fit, or '' for the default (the focal point). */
export function fitOf(overlay) {
	return !Array.isArray(overlay) && typeof overlay?.fit === 'string' ? overlay.fit : '';
}

/**
 * The overlay with new layers, keeping its other keys. A bare list stays a
 * bare list.
 */
export function withMarks(overlay, marks) {
	if (Array.isArray(overlay)) return [...marks];

	return { ...overlay, marks: [...marks] };
}

/**
 * The overlay with a new fit. '' removes the key (the default). A bare list
 * becomes `{fit, marks}`, the only shape that can carry a fit.
 */
export function withFit(overlay, fit) {
	const base = Array.isArray(overlay) ? { marks: [...overlay] } : { ...overlay };
	if (fit === '') {
		delete base.fit;
	} else {
		base.fit = fit;
	}

	return base;
}

/** The overlay as the field stores it. An empty overlay is `{}`, never null. */
export function serializeOverlay(overlay) {
	const onlyMarks = Array.isArray(overlay) || Object.keys(overlay ?? {}).every(key => key === 'marks');
	if (marksOf(overlay).length === 0 && onlyMarks) {
		return '{}';
	}

	return JSON.stringify(overlay, null, 2);
}

/** Whether there is anything to draw. */
export function hasLayers(overlay) {
	return marksOf(overlay).length > 0;
}

/**
 * Which of the four ImageWorks layer kinds a layer is (MarkLayers): logo,
 * image, fill or text. Null for a layer that draws nothing ImageWorks knows.
 */
export function layerKind(layer) {
	if (typeof layer?.markimage === 'string' && layer.markimage !== '') return 'image';
	if (typeof layer?.markfill === 'string' && layer.markfill !== '') return 'fill';
	if (typeof layer?.mark === 'string' && layer.mark !== '') return 'logo';
	if (layer?.marktext !== undefined && String(layer.marktext) !== '') return 'text';

	return null;
}

const PLACEHOLDER = /\$\{([^}]+)\}/;

/**
 * A short name for a layer, for the form row's summary.
 *
 * @param {object} layer
 * @param {(key: string, params?: object, fallback?: string) => string} t the translator
 */
export function layerName(layer, t) {
	switch (layerKind(layer)) {
		case 'fill':
			return layer.markgradient ? t('overlay.kind.fade', {}, 'fade') : t('overlay.kind.fill', {}, 'fill');
		case 'image':
			return t('overlay.kind.photo', {}, 'photo');
		case 'logo':
			return t('overlay.kind.logo', {}, 'logo');
		case 'text': {
			const text  = String(layer.marktext);
			const match = text.match(PLACEHOLDER);
			if (match) {
				const key = match[1].trim();
				if (key === 'socialTitle' || key === 'title') return t('overlay.kind.title', {}, 'title');
				if (key === 'site') return t('overlay.kind.site', {}, 'site name');

				return key;
			}
			const words = text.trim().split(/\s+/);

			return `“${words.slice(0, 3).join(' ')}${words.length > 3 ? '…' : ''}”`;
		}
		default:
			return t('overlay.kind.other', {}, 'layer');
	}
}

/** The form row's one-line summary of an overlay. */
export function summarize(overlay, t) {
	const marks = marksOf(overlay);
	if (marks.length === 0) return t('overlay.none', {}, 'No overlay. Shares use the plain image.');

	const names = marks.map(layer => layerName(layer, t)).join(', ');

	return marks.length === 1
		? t('overlay.summary_one', { names }, `1 layer · ${names}`)
		: t('overlay.summary', { count: marks.length, names }, `${marks.length} layers · ${names}`);
}

/** The fit modes the designer offers; any other fit is kept and shown as custom. */
export const FITS = ['', 'crop-top', 'crop-center', 'crop-bottom'];
