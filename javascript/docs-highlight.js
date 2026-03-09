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

// Export for potential manual use
export { hljs };
