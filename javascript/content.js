import Pagination from './pagination.js';

document.addEventListener("DOMContentLoaded", e => {
	const paginations = Array.from(document.getElementsByClassName('cms-pagination'));
	paginations.forEach(pagination => new Pagination(pagination));
});

const externalLinks = Array.from(document.querySelectorAll('a[title="external"]'));
externalLinks.forEach(link => {
	link.target = '_blank';
	link.removeAttribute('title');
});