import Pagination from './pagination.js';
import initExternalLinks from './external-links.js';
import './mailto-decoder.js';

document.addEventListener("DOMContentLoaded", e => {
	const paginations = Array.from(document.getElementsByClassName('cms-pagination'));
	paginations.forEach(pagination => new Pagination(pagination));

	initExternalLinks();

	// This should be moved to a content.js file
	const embeds = Array.from(document.getElementsByClassName("cms-video-embed"));
	embeds.forEach(iframe => iframe.src = iframe.dataset.src);
});
