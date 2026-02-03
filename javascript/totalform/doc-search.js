//-----------------------------------------------
// Total CMS Documentation Search Component
//-----------------------------------------------

export default class DocSearch {
	constructor(container, options = {}) {
		this.container = container;
		this.options = Object.assign({
			indexUrl: 'docs/search-index',
			minQueryLength: 2,
			maxResults: 20,
			highlightClass: 'search-highlight',
		}, options);

		this.index = null;
		this.searchInput = null;
		this.resultsContainer = null;

		this.init();
	}

	async init() {
		this.createUI();
		await this.loadIndex();
		this.bindEvents();
		this.searchInput.focus();
	}

	createUI() {
		// Create search input
		this.searchInput = document.createElement('input');
		this.searchInput.type = 'search';
		this.searchInput.placeholder = 'Search documentation...';
		this.searchInput.className = 'doc-search-input';
		this.searchInput.autofocus = true;

		// Create input wrapper (for icon positioning)
		const inputWrapper = document.createElement('div');
		inputWrapper.className = 'doc-search-input-wrapper';
		inputWrapper.appendChild(this.searchInput);

		// Create results container
		this.resultsContainer = document.createElement('div');
		this.resultsContainer.className = 'doc-search-results';

		// Create outer wrapper
		const wrapper = document.createElement('div');
		wrapper.className = 'doc-search-wrapper';
		wrapper.appendChild(inputWrapper);
		wrapper.appendChild(this.resultsContainer);

		this.container.appendChild(wrapper);
	}

	async loadIndex() {
		try {
			const response = await fetch(this.options.indexUrl);
			if (!response.ok) {
				throw new Error(`Failed to load search index: ${response.status}`);
			}
			this.index = await response.json();
		} catch (error) {
			console.error('DocSearch: Failed to load index', error);
			this.resultsContainer.innerHTML = '<p class="doc-search-error">Failed to load search index</p>';
		}
	}

	bindEvents() {
		let debounceTimer;

		this.searchInput.addEventListener('input', () => {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => {
				this.performSearch(this.searchInput.value);
			}, 150);
		});

		// Clear results on Escape
		this.searchInput.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				this.searchInput.value = '';
				this.clearResults();
			}
		});
	}

	performSearch(query) {
		if (!this.index) {
			return;
		}

		const trimmedQuery = query.trim().toLowerCase();

		if (trimmedQuery.length < this.options.minQueryLength) {
			this.clearResults();
			return;
		}

		const terms = trimmedQuery.split(/\s+/).filter(t => t.length > 0);
		const results = this.searchIndex(terms);

		this.displayResults(results, terms);
	}

	searchIndex(terms) {
		const results = [];

		for (const doc of this.index) {
			let score = 0;
			let matchedTerms = 0;

			for (const term of terms) {
				// Title match (highest priority)
				if (doc.title.toLowerCase().includes(term)) {
					score += 100;
					matchedTerms++;
				}
				// Keywords/sections match (high priority)
				else if (doc.keywords.includes(term)) {
					score += 50;
					matchedTerms++;
				}
				// Content match (standard priority)
				else if (doc.content.includes(term)) {
					score += 10;
					matchedTerms++;

					// Boost score based on frequency
					const frequency = (doc.content.match(new RegExp(term, 'gi')) || []).length;
					score += Math.min(frequency, 10);
				}
			}

			// Only include if all terms match
			if (matchedTerms === terms.length) {
				results.push({
					doc,
					score,
				});
			}
		}

		// Sort by score descending
		results.sort((a, b) => b.score - a.score);

		return results.slice(0, this.options.maxResults);
	}

	displayResults(results, terms) {
		if (results.length === 0) {
			this.resultsContainer.innerHTML = '<p class="doc-search-no-results">No results found</p>';
			return;
		}

		const html = results.map(({ doc }) => {
			// Find a relevant excerpt containing the search term
			let excerpt = doc.excerpt;

			// Try to find a better excerpt containing one of the terms
			for (const term of terms) {
				const idx = doc.content.indexOf(term);
				if (idx !== -1) {
					const start = Math.max(0, idx - 60);
					const end = Math.min(doc.content.length, idx + 140);
					excerpt = (start > 0 ? '...' : '') +
						doc.content.slice(start, end) +
						(end < doc.content.length ? '...' : '');
					break;
				}
			}

			// Build URL with highlight param
			const url = `docs/${doc.path}?highlight=${encodeURIComponent(terms.join(' '))}`;

			// Highlight terms in excerpt
			let highlightedExcerpt = this.escapeHtml(excerpt);
			for (const term of terms) {
				const regex = new RegExp(`(${this.escapeRegex(term)})`, 'gi');
				highlightedExcerpt = highlightedExcerpt.replace(regex, `<mark class="${this.options.highlightClass}">$1</mark>`);
			}

			// Get relevant sections
			const matchingSections = doc.sections
				.filter(s => terms.some(t => s.toLowerCase().includes(t)))
				.slice(0, 3);

			const sectionsHtml = matchingSections.length > 0
				? `<div class="doc-search-sections">${matchingSections.map(s => `<span>${this.escapeHtml(s)}</span>`).join('')}</div>`
				: '';

			return `
				<a href="${url}" class="doc-search-result">
					<h3>${this.escapeHtml(doc.title)}</h3>
					${sectionsHtml}
					<p>${highlightedExcerpt}</p>
				</a>
			`;
		}).join('');

		this.resultsContainer.innerHTML = html;
	}

	clearResults() {
		this.resultsContainer.innerHTML = '';
	}

	escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	escapeRegex(string) {
		return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}
}
