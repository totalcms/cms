/**
 * Quick Navigation (Shift+Cmd+O)
 *
 * Global admin command palette for jumping to any navigable page.
 * Reads its index from window.TCMS_QUICK_NAV (rendered by Twig).
 */
export default class QuickNav {
	constructor() {
		this.index = window.TCMS_QUICK_NAV || [];
		this.activeIndex = -1;
		this.filtered = [];
		this.dialog = null;
		this.input = null;
		this.list = null;

		this.buildDialog();
		this.bindGlobalShortcut();
	}

	buildDialog() {
		const dialog = document.createElement('dialog');
		dialog.className = 'quick-nav';
		dialog.innerHTML = `
			<input type="text" class="quick-nav-search" placeholder="Go to..." autocomplete="off" spellcheck="false" />
			<ul class="quick-nav-results"></ul>
			<div class="quick-nav-hint">
				<span><kbd>&uarr;</kbd> <kbd>&darr;</kbd> navigate</span>
				<span><kbd>Enter</kbd> open</span>
				<span><kbd>Esc</kbd> close</span>
			</div>
		`;

		document.body.appendChild(dialog);

		this.dialog = dialog;
		this.input = dialog.querySelector('.quick-nav-search');
		this.list = dialog.querySelector('.quick-nav-results');

		this.input.addEventListener('input', () => this.onInput());
		this.input.addEventListener('keydown', (e) => this.onKeydown(e));

		dialog.addEventListener('cancel', (e) => {
			e.preventDefault();
			this.close();
		});

		// Close on backdrop click
		dialog.addEventListener('click', (e) => {
			if (e.target === dialog) this.close();
		});
	}

	bindGlobalShortcut() {
		document.addEventListener('keydown', (e) => {
			if (e.shiftKey && (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'o') {
				e.preventDefault();
				this.open();
			}
		});

		const trigger = document.querySelector('.quick-nav-trigger');
		if (trigger) {
			trigger.addEventListener('click', () => this.open());
		}
	}

	open() {
		if (this.dialog.open) return;
		this.input.value = '';
		this.activeIndex = -1;
		this.filtered = [];
		this.list.innerHTML = '';
		this.dialog.showModal();
		this.input.focus();
	}

	close() {
		this.dialog.close();
	}

	onInput() {
		const query = this.input.value.trim().toLowerCase();

		if (query.length < 2) {
			this.filtered = [];
			this.activeIndex = -1;
			this.list.innerHTML = '';
			return;
		}

		const terms = query.split(/\s+/).filter(Boolean);

		const scored = [];
		for (const item of this.index) {
			const score = scoreItem(item, terms);
			if (score > 0) scored.push({ item, score });
		}

		scored.sort((a, b) => {
			if (b.score !== a.score) return b.score - a.score;
			return a.item.label.localeCompare(b.item.label);
		});

		this.filtered = scored.map(s => s.item);
		this.activeIndex = this.filtered.length > 0 ? 0 : -1;
		this.render();
	}

	onKeydown(e) {
		switch (e.key) {
			case 'ArrowDown':
				e.preventDefault();
				if (this.filtered.length === 0) return;
				this.activeIndex = (this.activeIndex + 1) % this.filtered.length;
				this.updateActive();
				break;

			case 'ArrowUp':
				e.preventDefault();
				if (this.filtered.length === 0) return;
				this.activeIndex = this.activeIndex <= 0
					? this.filtered.length - 1
					: this.activeIndex - 1;
				this.updateActive();
				break;

			case 'Enter':
				e.preventDefault();
				if (this.activeIndex >= 0 && this.activeIndex < this.filtered.length) {
					this.navigate(this.filtered[this.activeIndex]);
				}
				break;

			case 'Escape':
				e.preventDefault();
				this.close();
				break;
		}
	}

	navigate(item) {
		this.close();
		window.location.href = item.path;
	}

	render() {
		const query = this.input.value.trim().toLowerCase();

		if (this.filtered.length === 0 && query) {
			this.list.innerHTML = `<li class="quick-nav-empty">No results for "${escapeHtml(query)}"</li>`;
			return;
		}

		this.list.innerHTML = this.filtered.map((item, i) => {
			const activeClass = i === this.activeIndex ? ' active' : '';
			const label = query ? highlightMatch(item.label, query) : escapeHtml(item.label);
			const iconKey = iconKeyFor(item);
			return `<li class="quick-nav-item${activeClass}" data-index="${i}">
				<span class="quick-nav-icon" data-icon="${iconKey}"></span>
				<span class="quick-nav-section">${escapeHtml(item.section)}</span>
				<span class="quick-nav-label">${label}</span>
			</li>`;
		}).join('');

		// Bind click handlers
		this.list.querySelectorAll('.quick-nav-item').forEach(el => {
			el.addEventListener('click', () => {
				const idx = parseInt(el.dataset.index, 10);
				this.navigate(this.filtered[idx]);
			});
		});

		this.scrollActiveIntoView();
	}

	updateActive() {
		const items = this.list.querySelectorAll('.quick-nav-item');
		items.forEach((el, i) => {
			el.classList.toggle('active', i === this.activeIndex);
		});
		this.scrollActiveIntoView();
	}

	scrollActiveIntoView() {
		const active = this.list.querySelector('.quick-nav-item.active');
		if (active) {
			active.scrollIntoView({ block: 'nearest' });
		}
	}
}

function escapeHtml(str) {
	return String(str)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

function highlightMatch(text, query) {
	const escaped = escapeHtml(text);
	const terms = query.split(/\s+/).filter(Boolean);
	let result = escaped;

	for (const term of terms) {
		const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');
		result = result.replace(regex, '<mark>$1</mark>');
	}

	return result;
}

function escapeRegex(str) {
	return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const SECTION_PRIORITY = {
	'collections' : 10,
	'schemas'     : 9,
	'dataviews'   : 8,
	'builder'     : 7,
	'extensions'  : 6,
	'mailer'      : 5,
	'playground'  : 4,
	'utils'       : 3,
	'settings'    : 2,
	'docs'        : 1,
};

function categoryFor(item) {
	const sectionMap = {
		'Collections'       : 'collections',
		'Schemas'           : 'schemas',
		'Data Views'        : 'dataviews',
		'Builder Pages'     : 'builder',
		'Builder Templates' : 'builder',
		'Utilities'         : 'utils',
		'Settings'          : 'settings',
		'Docs'              : 'docs',
		'Extensions'        : 'extensions',
	};
	if (sectionMap[item.section]) return sectionMap[item.section];

	// Top-level Navigation: derive from path's first segment
	const first = String(item.path || '').split('/')[0];
	const navMap = {
		'collections' : 'collections',
		'schemas'     : 'schemas',
		'dataviews'   : 'dataviews',
		'builder'     : 'builder',
		'extensions'  : 'extensions',
		'mailer'      : 'mailer',
		'playground'  : 'playground',
		'utils'       : 'utils',
		'settings'    : 'settings',
		'docs'        : 'docs',
	};
	return navMap[first] || 'utils';
}

function iconKeyFor(item) {
	return categoryFor(item);
}

function scoreItem(item, terms) {
	const label = item.label.toLowerCase();
	const section = item.section.toLowerCase();
	const keywords = (item.keywords || '').toLowerCase();

	let total = 0;
	for (const term of terms) {
		const s = scoreTerm(term, label, section, keywords);
		if (s === 0) return 0;
		total += s;
	}
	return total + (SECTION_PRIORITY[categoryFor(item)] || 0);
}

function scoreTerm(term, label, section, keywords) {
	if (label === term) return 1000;
	if (label.startsWith(term + ' ') || label.startsWith(term + '-') || label.startsWith(term + '_')) return 600;
	if (label.startsWith(term)) return 400;

	const wordBoundary = new RegExp(`\\b${escapeRegex(term)}\\b`);
	if (wordBoundary.test(label)) return 300;
	if (label.includes(term)) return 150;

	if (wordBoundary.test(keywords)) return 50;
	if (keywords.includes(term)) return 25;

	if (section.includes(term)) return 10;

	return 0;
}
