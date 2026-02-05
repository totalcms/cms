//-----------------------------------------------
// Documentation Text Fragment Highlighter
// Supports both URL ?highlight= param and on-page search input
//-----------------------------------------------

let currentHighlightIndex = -1;
let allHighlights = [];
let searchInput = null;
let content = null;

export default function initDocHighlight() {
	try {
		// Find the content area
		content = document.querySelector('.doc-content');
		if (!content) {
			return;
		}

		// Don't show on docs homepage
		if (document.querySelector('.doc-homepage')) {
			return;
		}

		// Create simple search input
		createSearchInput();

		// Check for URL highlight param
		const params = new URLSearchParams(window.location.search);
		const highlightText = params.get('highlight');

		if (highlightText) {
			searchInput.value = highlightText;
			performSearch(highlightText);
		}
	} catch (e) {
		console.error('DocHighlight error:', e);
	}
}

function createSearchInput() {
	const wrapper = document.createElement('div');
	wrapper.className = 'doc-page-search';

	searchInput = document.createElement('input');
	searchInput.type = 'search';
	searchInput.className = 'doc-page-search-input';
	searchInput.placeholder = 'Search this page';

	wrapper.appendChild(searchInput);
	content.insertBefore(wrapper, content.firstChild);

	// Search on Enter
	searchInput.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			performSearch(searchInput.value);
		} else if (e.key === 'Escape') {
			clearSearch();
			searchInput.blur();
		}
	});

	// Global Ctrl+F override
	document.addEventListener('keydown', (e) => {
		if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
			e.preventDefault();
			searchInput.focus();
			searchInput.select();
		}
	});
}

function performSearch(query) {
	// Clear previous highlights
	clearHighlights();

	const trimmedQuery = query.trim();
	if (trimmedQuery.length < 2) {
		return;
	}

	// Highlight all matches for each term
	const terms = trimmedQuery.split(' ').filter(t => t.length > 0);
	let firstMatch = null;

	for (const term of terms) {
		const match = highlightTextNodes(content, term);
		if (!firstMatch && match) {
			firstMatch = match;
		}
	}

	// Collect all highlights for navigation
	allHighlights = Array.from(content.querySelectorAll('.doc-text-highlight'));

	// Show navigation UI if there are multiple matches
	if (allHighlights.length > 1) {
		createNavigationUI();
	}

	// Scroll to first match
	if (firstMatch) {
		firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
		setCurrentHighlight(firstMatch);
	}
}

function clearSearch() {
	if (searchInput) {
		searchInput.value = '';
	}
	clearHighlights();
}

function clearHighlights() {
	// Remove floating nav
	const nav = document.querySelector('.doc-highlight-nav');
	if (nav) {
		nav.remove();
	}

	// Remove all highlight marks and restore original text
	const highlights = content.querySelectorAll('.doc-text-highlight');
	highlights.forEach(mark => {
		const parent = mark.parentNode;
		const text = document.createTextNode(mark.textContent);
		parent.replaceChild(text, mark);
		parent.normalize();
	});

	allHighlights = [];
	currentHighlightIndex = -1;
}

function setCurrentHighlight(element) {
	allHighlights.forEach(h => h.classList.remove('doc-text-highlight-current'));

	currentHighlightIndex = allHighlights.indexOf(element);
	if (currentHighlightIndex >= 0) {
		element.classList.add('doc-text-highlight-current');
	}

	updateNavigationCounter();
}

function navigateHighlight(direction) {
	if (allHighlights.length === 0) return;

	currentHighlightIndex += direction;

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
	// Remove existing nav if present
	const existingNav = document.querySelector('.doc-highlight-nav');
	if (existingNav) {
		existingNav.remove();
	}

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
		{
			acceptNode: (node) => {
				if (node.parentNode.closest('.doc-page-search')) {
					return NodeFilter.FILTER_REJECT;
				}
				return NodeFilter.FILTER_ACCEPT;
			}
		}
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
