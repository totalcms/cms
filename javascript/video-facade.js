/**
 * Video facade — click-to-play swap for `cms.render.video(..., {facade: true})`.
 *
 * Markup: `<div class="cms-video-facade" data-embed="...">` wrapping a poster
 * `<img>` and a play `<button>`. On click, the real iframe is built with
 * createElement (never innerHTML from the data-embed value) and slid in
 * UNDER the poster; the poster and button only go once the iframe has
 * loaded, so the click never shows an empty box while the player boots.
 *
 * On the first pointer-over (or touch-start) the provider's origins get
 * `preconnect` hints, the way the well-known lite embeds warm up: by the
 * time the click lands, DNS + TLS to the player host are already done,
 * which is most of the latency a cold iframe pays. No bytes of player are
 * fetched until the click.
 */
const ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';

// If the iframe never fires `load` (some players swallow it, or the network
// is dead) the poster must not hang around forever over a possibly-playing
// player — drop it after this long regardless.
const POSTER_HOLD_MAX_MS = 4000;

// Extra origins each player host pulls from once it boots. The embed URL's
// own origin is always warmed; these are the second hop.
const EXTRA_ORIGINS = {
	'www.youtube-nocookie.com': ['https://www.google.com', 'https://i.ytimg.com'],
	'www.youtube.com'         : ['https://www.google.com', 'https://i.ytimg.com'],
	'player.vimeo.com'        : ['https://i.vimeocdn.com', 'https://f.vimeocdn.com'],
	'fast.wistia.net'         : ['https://fast.wistia.com', 'https://embed-ssl.wistia.com'],
};

const warmedOrigins = new Set();
const warmedFacades = new WeakSet();

function preconnect(origin) {
	if (warmedOrigins.has(origin)) return;
	warmedOrigins.add(origin);

	const link = document.createElement('link');
	link.rel = 'preconnect';
	link.href = origin;
	link.crossOrigin = '';
	document.head.appendChild(link);
}

export function warmFacade(facade) {
	if (warmedFacades.has(facade)) return;
	warmedFacades.add(facade);

	let url;
	try {
		url = new URL(facade.dataset.embed || '', document.baseURI);
	} catch {
		return;
	}
	if (url.protocol !== 'https:' && url.protocol !== 'http:') return;

	preconnect(url.origin);
	(EXTRA_ORIGINS[url.hostname] || []).forEach(preconnect);
}

function playFacade(facade) {
	const embed = facade.dataset.embed;
	if (!embed) return;
	// A second click while the player is still loading (or already up)
	// must not build a second iframe.
	if (facade.classList.contains('is-loading') || facade.classList.contains('is-playing')) return;

	const poster = facade.querySelector('img');
	const title  = poster ? poster.alt : '';

	const iframe = document.createElement('iframe');
	iframe.src = embed;
	if (title) iframe.title = title;
	iframe.allow = ALLOW;
	iframe.allowFullscreen = true;
	iframe.referrerPolicy = 'strict-origin-when-cross-origin';

	facade.classList.add('is-loading');

	let revealed = false;
	const reveal = () => {
		if (revealed) return;
		revealed = true;
		clearTimeout(timer);
		// Everything that isn't the iframe — the poster and the play button.
		Array.from(facade.children).forEach(child => { if (child !== iframe) child.remove(); });
		facade.classList.remove('is-loading');
		facade.classList.add('is-playing');
	};
	const timer = setTimeout(reveal, POSTER_HOLD_MAX_MS);

	iframe.addEventListener('load', reveal, { once: true });
	// Under the poster: first child, and the CSS stacks later siblings above it.
	facade.prepend(iframe);
}

export default function initVideoFacades() {
	// Delegated on document — facades can be added to the page after load
	// (HTMX-loaded fragments, load-more results) without re-wiring listeners.
	document.addEventListener('click', e => {
		// The whole facade is clickable (it's styled cursor:pointer), not just
		// the play button nested inside it — delegate on the element itself so
		// the pointer cursor isn't a lie.
		const facade = e.target.closest('.cms-video-facade');
		if (!facade) return;

		playFacade(facade);
	});

	// Warm the player's origins as soon as the pointer arrives (or a finger
	// lands, on touch). Passive: never blocks scrolling.
	const warm = e => {
		const facade = e.target.closest && e.target.closest('.cms-video-facade');
		if (facade) warmFacade(facade);
	};
	document.addEventListener('pointerover', warm, { passive: true });
	document.addEventListener('touchstart', warm, { passive: true });
}
