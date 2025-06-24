export default function initExternalLinks() {
	const externalLinks = Array.from(document.querySelectorAll('a[title="external"]'));
	externalLinks.forEach(link => {
		link.target = '_blank';
		link.rel    = 'noopener noreferrer';
		link.removeAttribute('title');
	});
}