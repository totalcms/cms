//-----------------------------------------------
// Documentation Text Fragment Highlighter
// Parses #:~:text= fragments and highlights all matches
//-----------------------------------------------

export default function initDocHighlight() {
	try {
		const params = new URLSearchParams(window.location.search);
		const highlightText = params.get('highlight');
		const scrollTo = params.get('scrollto');

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

		// Scroll to scrollto text if provided, otherwise first match
		if (scrollTo) {
			const scrollTarget = findTextInContent(content, scrollTo);
			if (scrollTarget) {
				scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}
		}

		if (firstMatch) {
			firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	} catch (e) {
		console.error('DocHighlight error:', e);
	}
}

function findTextInContent(container, searchText) {
	const searchLower = searchText.toLowerCase();

	// First check headings (h1-h6) for the section header
	const headings = container.querySelectorAll('h1, h2, h3, h4, h5, h6');
	for (const heading of headings) {
		if (heading.textContent.toLowerCase().includes(searchLower)) {
			return heading;
		}
	}

	// Then look for a highlight mark containing this text
	const marks = container.querySelectorAll('.doc-text-highlight');
	for (const mark of marks) {
		if (mark.textContent.toLowerCase().includes(searchLower)) {
			return mark;
		}
	}

	return null;
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
