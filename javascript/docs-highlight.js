/**
 * Documentation Syntax Highlighting
 * Uses highlight.js for code blocks in documentation pages
 */

import hljs from 'highlight.js/lib/core';

// Import only the languages we need
import twig from 'highlight.js/lib/languages/twig';
import json from 'highlight.js/lib/languages/json';
import javascript from 'highlight.js/lib/languages/javascript';
import bash from 'highlight.js/lib/languages/bash';
import xml from 'highlight.js/lib/languages/xml'; // HTML is part of xml
import php from 'highlight.js/lib/languages/php';
import css from 'highlight.js/lib/languages/css';
import apache from 'highlight.js/lib/languages/apache';

// Register languages
hljs.registerLanguage('twig', twig);
hljs.registerLanguage('json', json);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('js', javascript);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('shell', bash);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('php', php);
hljs.registerLanguage('css', css);
hljs.registerLanguage('apache', apache);

// Also register 'http' as plain text since highlight.js doesn't have a dedicated http mode
hljs.registerLanguage('http', () => ({ contains: [] }));

// Initialize highlighting
hljs.highlightAll();

// Add copy buttons to all code blocks
function addCopyButtons() {
	document.querySelectorAll('pre').forEach(pre => {
		// Skip if already has a copy button
		if (pre.querySelector('.docs-copy-btn')) return;

		// Create wrapper for positioning
		pre.style.position = 'relative';

		// Create copy button
		const button = document.createElement('button');
		button.className = 'docs-copy-btn';
		button.textContent = 'Copy';
		button.title = 'Copy to clipboard';

		button.addEventListener('click', () => {
			const code = pre.querySelector('code');
			const text = code ? code.textContent : pre.textContent;

			if (!navigator.clipboard?.writeText) return;
			navigator.clipboard.writeText(text).then(() => {
				button.textContent = 'Copied!';
				button.classList.add('copied');

				setTimeout(() => {
					button.textContent = 'Copy';
					button.classList.remove('copied');
				}, 2000);
			}).catch(err => {
				console.warn('Copy failed:', err);
			});
		});

		pre.appendChild(button);
	});
}

addCopyButtons();

// TOC scroll-spy: highlight the on-page TOC entry matching the topmost visible heading.
// The doc-content scrolls inside .dash-main-content, so the observer tracks intersections
// relative to that container, not the window.
function setupTocScrollSpy() {
	const tocLinks = document.querySelectorAll('.doc-toc a[data-toc-target]');
	if (!tocLinks.length) return;

	const idToLink = new Map();
	tocLinks.forEach(a => idToLink.set(a.dataset.tocTarget, a));

	const headings = [];
	idToLink.forEach((_, id) => {
		const el = document.getElementById(id);
		if (el) headings.push(el);
	});
	if (!headings.length) return;

	const scrollRoot = document.querySelector('.dash-main-content');
	let activeId = null;
	const setActive = (id) => {
		if (id === activeId) return;
		activeId = id;
		tocLinks.forEach(a => a.classList.toggle('active', a.dataset.tocTarget === id));
	};

	// Track headings near the top quarter of the scroll viewport
	const observer = new IntersectionObserver((entries) => {
		const visible = entries
			.filter(e => e.isIntersecting)
			.map(e => ({ id: e.target.id, top: e.boundingClientRect.top }))
			.sort((a, b) => a.top - b.top);
		if (visible.length) {
			setActive(visible[0].id);
		}
	}, {
		root: scrollRoot,
		rootMargin: '0px 0px -75% 0px',
		threshold: [0, 1.0],
	});

	headings.forEach(h => observer.observe(h));
	setActive(headings[0].id);
}

setupTocScrollSpy();

// Export for potential manual use
export { hljs };
