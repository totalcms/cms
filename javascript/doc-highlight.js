//-----------------------------------------------
// Documentation Text Fragment Highlighter
// Parses #:~:text= fragments and highlights all matches
//-----------------------------------------------

let currentHighlightIndex = -1;
let allHighlights = [];

export default function initDocHighlight() {
	try {
		const params = new URLSearchParams(window.location.search);
		const highlightText = params.get('highlight');

		if (!highlightText) {
			return;
		}

		// Find the content area
		const content = document.querySelector('.doc-content');
		if (!content) {
			return;
		}

		// Highlight all matches for each term
		const terms = highlightText.split(' ').filter(t => t.length > 0);
		let firstMatch = null;

		for (const term of terms) {
			const match = highlightTextNodes(content, term);
			if (!firstMatch && match) {
				firstMatch = match;
			}
		}

		// Collect all highlights for navigation
		allHighlights = Array.from(content.querySelectorAll('.doc-text-highlight'));

		// Only show navigation if there are multiple highlights
		if (allHighlights.length > 1) {
			createNavigationUI();
		}

		// Scroll to first match
		if (firstMatch) {
			firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
			setCurrentHighlight(firstMatch);
		}
	} catch (e) {
		console.error('DocHighlight error:', e);
	}
}

function setCurrentHighlight(element) {
	// Remove current class from all
	allHighlights.forEach(h => h.classList.remove('doc-text-highlight-current'));

	// Find index of this element
	currentHighlightIndex = allHighlights.indexOf(element);
	if (currentHighlightIndex >= 0) {
		element.classList.add('doc-text-highlight-current');
	}

	updateNavigationCounter();
}

function navigateHighlight(direction) {
	if (allHighlights.length === 0) return;

	currentHighlightIndex += direction;

	// Wrap around
	if (currentHighlightIndex >= allHighlights.length) {
		currentHighlightIndex = 0;
	} else if (currentHighlightIndex < 0) {
		currentHighlightIndex = allHighlights.length - 1;
	}

	const target = allHighlights[currentHighlightIndex];
	target.scrollIntoView({ behavior: 'smooth', block: 'center' });
	setCurrentHighlight(target);
}

function updateNavigationCounter() {
	const counter = document.querySelector('.doc-highlight-counter');
	if (counter) {
		counter.textContent = `${currentHighlightIndex + 1} / ${allHighlights.length}`;
	}
}

function createNavigationUI() {
	const nav = document.createElement('div');
	nav.className = 'doc-highlight-nav';
	nav.innerHTML = `
		<button class="doc-highlight-prev" title="Previous match">&uarr;</button>
		<span class="doc-highlight-counter">1 / ${allHighlights.length}</span>
		<button class="doc-highlight-next" title="Next match">&darr;</button>
	`;

	nav.querySelector('.doc-highlight-prev').addEventListener('click', () => navigateHighlight(-1));
	nav.querySelector('.doc-highlight-next').addEventListener('click', () => navigateHighlight(1));

	document.body.appendChild(nav);
}

function highlightTextNodes(container, searchText) {
	const walker = document.createTreeWalker(
		container,
		NodeFilter.SHOW_TEXT,
		null,
		false
	);

	const textNodes = [];
	let node;
	while ((node = walker.nextNode())) {
		if (node.nodeValue.toLowerCase().includes(searchText.toLowerCase())) {
			textNodes.push(node);
		}
	}

	let firstMatch = null;
	textNodes.forEach(textNode => {
		const text = textNode.nodeValue;
		const regex = new RegExp(`(${escapeRegex(searchText)})`, 'gi');
		const parts = text.split(regex);

		if (parts.length <= 1) {
			return;
		}

		const fragment = document.createDocumentFragment();
		parts.forEach(part => {
			if (part.toLowerCase() === searchText.toLowerCase()) {
				const mark = document.createElement('mark');
				mark.className = 'doc-text-highlight';
				mark.textContent = part;
				fragment.appendChild(mark);
				if (!firstMatch) {
					firstMatch = mark;
				}
			} else {
				fragment.appendChild(document.createTextNode(part));
			}
		});

		textNode.parentNode.replaceChild(fragment, textNode);
	});

	return firstMatch;
}

function escapeRegex(string) {
	return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
