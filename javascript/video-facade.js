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
 * which is most of the latency a cold iframe pays.
 *
 * A player that takes a "play" message from the page (CONTROLLED below) goes
 * one step further: once the pointer rests on the poster, or the facade
 * holds keyboard focus, the iframe is built under the poster with autoplay
 * off, and the click sends "play" instead of building anything. That is
 * what lets Safari start with sound on the first click. Safari does not
 * hand the click's permission to an iframe created after it, so a player
 * built on click and told to autoplay stays paused there; a message posted
 * inside the click handler to a player that is already up carries it.
 */
const ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';

// If the iframe never fires `load` (some players swallow it, or the network
// is dead) the poster must not hang around forever over a possibly-playing
// player — drop it after this long regardless.
const POSTER_HOLD_MAX_MS = 4000;

// How long the pointer (or keyboard focus) must stay on a facade before its
// player is preloaded, so a pointer crossing the poster on its way elsewhere
// does not load a player nobody asked for.
const PRELOAD_INTENT_MS = 100;

// Extra origins each player host pulls from once it boots. The embed URL's
// own origin is always warmed; these are the second hop.
const EXTRA_ORIGINS = {
	'www.youtube-nocookie.com': ['https://www.google.com', 'https://i.ytimg.com'],
	'www.youtube.com'         : ['https://www.google.com', 'https://i.ytimg.com'],
	'player.vimeo.com'        : ['https://i.vimeocdn.com', 'https://f.vimeocdn.com'],
	'fast.wistia.net'         : ['https://fast.wistia.com', 'https://embed-ssl.wistia.com'],
	// The Privacy Player asks the load balancer for its playlist and poster,
	// which then redirect to a per-session CDN edge that cannot be known ahead.
	'player.jet-stream.com'   : ['https://takeoff.jetstre.am'],
};

// The player.js protocol (https://github.com/embedly/player.js): JSON strings
// over postMessage. The player announces itself with a `ready` event and
// takes `play` as a method.
const PLAYER_JS = {
	// Autoplay off while the player waits under the poster. For Livid an
	// explicit 0 also overrides the video's own "Autoplay on load" setting.
	idle  : url => url.searchParams.set('autoplay', '0'),
	ready : data => data.context === 'player.js' && data.event === 'ready',
	play  : () => JSON.stringify({ context: 'player.js', version: '2.0', method: 'play' }),
};

// Player hosts that can be preloaded and then started by a message. Only
// hosts whose player was tested this way belong here: a player that ignores
// the idle URL would start playing under the poster on hover.
const CONTROLLED = new Map([
	['livid.com', PLAYER_JS],
]);

const warmedOrigins = new Set();
const warmedFacades = new WeakSet();

// facade → { facade, iframe, origin, control, ready, playRequested, holdTimer }
const preloads = new WeakMap();
// Preloaded players that have not announced `ready` yet.
const awaitingReady = new Set();
// facade → the timer counting down to its preload.
const intents = new WeakMap();

function preconnect(origin) {
	if (warmedOrigins.has(origin)) return;
	warmedOrigins.add(origin);

	const link = document.createElement('link');
	link.rel = 'preconnect';
	link.href = origin;
	link.crossOrigin = '';
	document.head.appendChild(link);
}

function embedUrl(facade) {
	try {
		return new URL(facade.dataset.embed || '', document.baseURI);
	} catch {
		return null;
	}
}

export function warmFacade(facade) {
	if (warmedFacades.has(facade)) return;
	warmedFacades.add(facade);

	const url = embedUrl(facade);
	if (!url || (url.protocol !== 'https:' && url.protocol !== 'http:')) return;

	preconnect(url.origin);
	(EXTRA_ORIGINS[url.hostname] || []).forEach(preconnect);
}

function isBusy(facade) {
	return facade.classList.contains('is-loading') || facade.classList.contains('is-playing');
}

function buildIframe(facade, src) {
	const poster = facade.querySelector('img');
	const title  = poster ? poster.alt : '';

	const iframe = document.createElement('iframe');
	iframe.src = src;
	if (title) iframe.title = title;
	iframe.allow = ALLOW;
	iframe.allowFullscreen = true;
	iframe.referrerPolicy = 'strict-origin-when-cross-origin';

	return iframe;
}

// Drop everything that isn't the iframe — the poster and the play button.
function reveal(facade, iframe) {
	Array.from(facade.children).forEach(child => { if (child !== iframe) child.remove(); });
	iframe.removeAttribute('inert');
	facade.classList.remove('is-loading');
	facade.classList.add('is-playing');
}

/**
 * Build a controllable player under the poster, paused, ahead of the click.
 * Does nothing for a player that cannot be started by a message, for a
 * facade already loading or playing, or when the visitor asked to save data.
 */
export function preloadFacade(facade) {
	if (preloads.has(facade) || isBusy(facade)) return;
	if (navigator.connection && navigator.connection.saveData) return;

	const url     = embedUrl(facade);
	const control = url && url.protocol === 'https:' ? CONTROLLED.get(url.hostname) : undefined;
	if (!control) return;

	control.idle(url);

	const iframe = buildIframe(facade, url.href);
	// Under the poster it must not take focus or be read out: the visitor
	// has not opened the video yet.
	iframe.setAttribute('inert', '');

	const state = { facade, iframe, origin: url.origin, control, ready: false, playRequested: false, holdTimer: undefined };
	preloads.set(facade, state);
	awaitingReady.add(state);

	// Under the poster: first child, and the CSS stacks later siblings above it.
	facade.prepend(iframe);
}

function startPreloaded(state) {
	clearTimeout(state.holdTimer);

	const target = state.iframe.contentWindow;
	if (target) target.postMessage(state.control.play(), state.origin);

	reveal(state.facade, state.iframe);
}

function onPlayerMessage(e) {
	for (const state of awaitingReady) {
		// The facade left the page (an HTMX swap) before its player was ready.
		if (!state.iframe.isConnected) {
			awaitingReady.delete(state);
			continue;
		}
		if (e.source !== state.iframe.contentWindow || e.origin !== state.origin) continue;

		let data = e.data;
		if (typeof data === 'string') {
			try {
				data = JSON.parse(data);
			} catch {
				return;
			}
		}
		if (!data || typeof data !== 'object' || !state.control.ready(data)) return;

		awaitingReady.delete(state);
		state.ready = true;
		// The click came while the player was still booting.
		if (state.playRequested) startPreloaded(state);

		return;
	}
}

function playFacade(facade) {
	const embed = facade.dataset.embed;
	if (!embed) return;
	// A second click while the player is still loading (or already up)
	// must not build a second iframe.
	if (isBusy(facade)) return;

	const preloaded = preloads.get(facade);
	if (preloaded) {
		// Ready: "play" goes out inside this click handler, which is what
		// Safari needs to allow sound. Nothing asynchronous may come first.
		if (preloaded.ready) {
			startPreloaded(preloaded);

			return;
		}

		// Still booting: hold the poster and start it when it reports ready.
		// Chrome plays then; Safari leaves it paused on the player's own play
		// button, the same as a player built on click.
		preloaded.playRequested = true;
		facade.classList.add('is-loading');
		preloaded.holdTimer = setTimeout(() => reveal(facade, preloaded.iframe), POSTER_HOLD_MAX_MS);

		return;
	}

	const iframe = buildIframe(facade, embed);

	facade.classList.add('is-loading');

	let revealed = false;
	const show = () => {
		if (revealed) return;
		revealed = true;
		clearTimeout(timer);
		reveal(facade, iframe);
	};
	const timer = setTimeout(show, POSTER_HOLD_MAX_MS);

	iframe.addEventListener('load', show, { once: true });
	// Under the poster: first child, and the CSS stacks later siblings above it.
	facade.prepend(iframe);
}

function scheduleIntent(facade) {
	if (intents.has(facade) || preloads.has(facade) || isBusy(facade)) return;

	intents.set(facade, setTimeout(() => {
		intents.delete(facade);
		preloadFacade(facade);
	}, PRELOAD_INTENT_MS));
}

function cancelIntent(facade) {
	clearTimeout(intents.get(facade));
	intents.delete(facade);
}

function facadeOf(e) {
	return e.target.closest ? e.target.closest('.cms-video-facade') : null;
}

export default function initVideoFacades() {
	// Delegated on document — facades can be added to the page after load
	// (HTMX-loaded fragments, load-more results) without re-wiring listeners.
	document.addEventListener('click', e => {
		// The whole facade is clickable (it's styled cursor:pointer), not just
		// the play button nested inside it — delegate on the element itself so
		// the pointer cursor isn't a lie.
		const facade = facadeOf(e);
		if (!facade) return;

		cancelIntent(facade);
		playFacade(facade);
	});

	// Warm the player's origins as soon as the pointer arrives (or a finger
	// lands, on touch). Passive: never blocks scrolling.
	document.addEventListener('pointerover', e => {
		const facade = facadeOf(e);
		if (!facade) return;

		warmFacade(facade);
		// A finger landing is as likely a scroll as a tap, and the tap that
		// follows comes too soon for a preload to finish: touch only warms.
		if (e.pointerType !== 'touch') scheduleIntent(facade);
	}, { passive: true });
	document.addEventListener('touchstart', e => {
		const facade = facadeOf(e);
		if (facade) warmFacade(facade);
	}, { passive: true });

	// Leaving the facade (not moving between its poster and button) calls
	// the preload off.
	const leave = e => {
		const facade = facadeOf(e);
		if (facade && !facade.contains(e.relatedTarget)) cancelIntent(facade);
	};
	document.addEventListener('pointerout', leave, { passive: true });
	document.addEventListener('focusout', leave);

	// Keyboard: the play button taking focus is the same intent as a hover.
	document.addEventListener('focusin', e => {
		const facade = facadeOf(e);
		if (facade) scheduleIntent(facade);
	});

	window.addEventListener('message', onPlayerMessage);
}
