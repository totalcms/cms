import { csrfHeaders } from '../../csrf';

//-----------------------------------------------
// Talks to the overlay designer's admin routes (OverlayPreviewAction,
// OverlayPreviewOptionsAction). URLs are relative: every admin page sets
// `<base href>` to the dashboard.
//-----------------------------------------------

export const PREVIEW_URL = 'seo/overlay-preview';
export const OPTIONS_URL = 'seo/overlay-preview/options';

/** The record picker's options: collections, records, a default record, presets. */
export async function fetchOptions(collection = '', query = '', fetcher = fetch) {
	const params = new URLSearchParams();
	if (collection) params.set('collection', collection);
	if (query) params.set('q', query);

	const response = await fetcher(`${OPTIONS_URL}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
	if (!response.ok) throw new Error(`HTTP ${response.status}`);

	return response.json();
}

/** One draft drawn on one record: `{reason, message, layer, asIs, image}`. */
export async function fetchPreview({ collection, id, overlay, size = 'full' }, fetcher = fetch) {
	const response = await fetcher(PREVIEW_URL, {
		method      : 'POST',
		credentials : 'same-origin',
		headers     : { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
		body        : JSON.stringify({ collection, id, overlay, size }),
	});
	if (!response.ok) throw new Error(`HTTP ${response.status}`);

	return response.json();
}

/**
 * The live preview: debounces requests and drops any answer that arrives
 * after a newer request was sent, so a slow draw never overwrites the
 * latest one.
 */
export class LivePreview {
	constructor(onResult, { delay = 400, request = fetchPreview } = {}) {
		this.onResult = onResult;
		this.delay    = delay;
		this.request  = request;
		this.sequence = 0;
		this.timer    = null;
	}

	/** Ask for a preview after the debounce delay; a newer call replaces it. */
	schedule(body) {
		clearTimeout(this.timer);
		this.timer = setTimeout(() => this.now(body), this.delay);
	}

	/** Ask for a preview straight away. Resolves once the answer is handled or dropped. */
	async now(body) {
		clearTimeout(this.timer);
		const sequence = ++this.sequence;

		let result;
		try {
			result = await this.request(body);
		} catch (error) {
			result = { error };
		}

		if (sequence === this.sequence) {
			this.onResult(result);
		}
	}

	cancel() {
		clearTimeout(this.timer);
		this.sequence++;
	}
}
