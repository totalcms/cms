//-----------------------------------------------
// Documentation Text Fragment Highlighter
// Parses #:~:text= fragments and highlights all matches
//-----------------------------------------------

export default function initDocHighlight() {
	try {
		// Get highlight term from query parameter
		const params = new URLSearchParams(window.location.search);
		const searchText = params.get('highlight');

		if (!searchText) {
			return;
		}

		// Find the content area
		const content = document.querySelector('.doc-content');
		if (!content) {
			return;
		}

		// Highlight all matches (browser handles scrolling via text fragment)
		highlightText(content, searchText);
	} catch (e) {
		console.error('DocHighlight error:', e);
	}
}

function highlightText(container, searchText) {
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

	// Scroll to first match
	if (firstMatch) {
		firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
}

function escapeRegex(string) {
	return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
