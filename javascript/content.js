import Pagination from './pagination.js';
import initExternalLinks from './external-links.js';
import './mailto-decoder.js';

document.addEventListener("DOMContentLoaded", e => {
	const paginations = Array.from(document.getElementsByClassName('cms-pagination'));
	paginations.forEach(pagination => new Pagination(pagination));

	initExternalLinks();
});
