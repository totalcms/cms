/**
 * Video facade — click-to-play swap for `cms.render.video(..., {facade: true})`.
 *
 * Markup: `<div class="cms-video-facade" data-embed="...">` wrapping a poster
 * `<img>` and a play `<button>`. On click, the real iframe is built with
 * createElement (never innerHTML from the data-embed value) and swapped in.
 */
const ALLOW = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';

function playFacade(facade) {
	const embed = facade.dataset.embed;
	if (!embed) return;

	const poster = facade.querySelector('img');
	const title  = poster ? poster.alt : '';

	const iframe = document.createElement('iframe');
	iframe.src = embed;
	if (title) iframe.title = title;
	iframe.loading = 'lazy';
	iframe.allow = ALLOW;
	iframe.allowFullscreen = true;
	iframe.referrerPolicy = 'strict-origin-when-cross-origin';
	iframe.style.width = '100%';
	iframe.style.height = '100%';
	iframe.style.border = '0';

	facade.replaceChildren(iframe);
	facade.classList.add('is-playing');
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
}
