// @vitest-environment jsdom
import { beforeEach, describe, expect, test } from 'vitest';
import { initExtensionsList, matches, normalize } from '../../javascript/extensions-list.js';

// The Extensions page: a search box and a status filter over one row per
// extension, so one extension can be found among many.

const row = (id, name, status, { attention = false, text = '' } = {}) => `
	<li class="ext-row" id="ext-${id}" data-status="${status}" data-attention="${attention}" data-search="${name} ${id} ${text}">
		<h3 class="ext-name">${name}</h3>
	</li>`;

const page = () => `
	<div class="ext-toolbar" hidden>
		<input type="search" class="ext-search">
		<div class="ext-filter">
			<button type="button" data-show="all" aria-pressed="true">All</button>
			<button type="button" data-show="enabled" aria-pressed="false">Enabled</button>
			<button type="button" data-show="disabled" aria-pressed="false">Disabled</button>
			<button type="button" data-show="attention" aria-pressed="false">Needs attention</button>
		</div>
	</div>
	<section class="ext-group" data-group="enabled">
		<h2 class="ext-group-heading">Enabled <span class="ext-count">3</span></h2>
		<ul class="ext-list">
			${row('protect', 'Protect', 'enabled', { text: 'Gate a page behind a passcode' })}
			${row('s3-uploads', 'S3 Uploads', 'enabled', { text: 'Store large files in your own bucket' })}
			${row('podcast', 'Podcast', 'enabled', { attention: true, text: 'Host a podcast feed' })}
		</ul>
	</section>
	<section class="ext-group" data-group="disabled">
		<h2 class="ext-group-heading">Disabled <span class="ext-count">2</span></h2>
		<ul class="ext-list">
			${row('ab-split', 'A/B Split', 'disabled', { text: 'Split traffic between two pages' })}
			${row('cafe', 'Café Menu', 'disabled', { text: 'Menus for restaurants' })}
		</ul>
	</section>
	<div class="ext-no-match" hidden><p>Nothing matches <strong class="ext-no-match-term"></strong>.</p><button type="button" class="ext-clear">Show all</button></div>`;

const shown = () => [...document.querySelectorAll('.ext-row')].filter(el => !el.hidden).map(el => el.id.replace('ext-', ''));
const type  = value => {
	const input = document.querySelector('.ext-search');
	input.value = value;
	input.dispatchEvent(new Event('input', { bubbles: true }));
};
const press = show => document.querySelector(`.ext-filter [data-show="${show}"]`).click();

beforeEach(() => {
	localStorage.clear();
	document.body.innerHTML = page();
});

describe('normalize', () => {
	test('lowercases, trims and drops accents, so a search does not depend on them', () => {
		expect(normalize('  Café MENU ')).toBe('cafe menu');
		expect(normalize(undefined)).toBe('');
	});
});

describe('matches', () => {
	const ext = { search: normalize('S3 Uploads totalcms/s3-uploads Store large files'), status: 'enabled', attention: false };

	test('every word of the search must appear, in any order', () => {
		expect(matches(ext, { query: 'uploads s3', show: 'all' })).toBe(true);
		expect(matches(ext, { query: 'large bucket', show: 'all' })).toBe(false);
		expect(matches(ext, { query: '', show: 'all' })).toBe(true);
	});

	test('the status filter narrows what the search may find', () => {
		expect(matches(ext, { query: 's3', show: 'enabled' })).toBe(true);
		expect(matches(ext, { query: 's3', show: 'disabled' })).toBe(false);
		expect(matches(ext, { query: '', show: 'attention' })).toBe(false);
		expect(matches({ ...ext, attention: true }, { query: '', show: 'attention' })).toBe(true);
	});
});

describe('the Extensions page list', () => {
	test('the toolbar appears once the script can drive it, with every row still shown', () => {
		initExtensionsList();

		expect(document.querySelector('.ext-toolbar').hidden).toBe(false);
		expect(shown()).toEqual(['protect', 's3-uploads', 'podcast', 'ab-split', 'cafe']);
	});

	test('typing narrows the list as you type, by name or by what the extension does', () => {
		initExtensionsList();

		type('pod');
		expect(shown()).toEqual(['podcast']);

		type('bucket');
		expect(shown()).toEqual(['s3-uploads']);

		type('cafe');
		expect(shown()).toEqual(['cafe']);
	});

	test('a group with nothing left to show goes away with its heading', () => {
		initExtensionsList();
		type('protect');

		const [enabled, disabled] = document.querySelectorAll('.ext-group');
		expect(enabled.hidden).toBe(false);
		expect(disabled.hidden).toBe(true);
	});

	test('a group\'s count says how many of its rows the search left', () => {
		initExtensionsList();
		const count = () => document.querySelector('[data-group="enabled"] .ext-count').textContent;

		expect(count()).toBe('3');

		type('pod');
		expect(count()).toBe('1 of 3');

		type('');
		expect(count()).toBe('3');
	});

	test('the status filter shows one kind, and says which is pressed', () => {
		initExtensionsList();
		press('disabled');

		expect(shown()).toEqual(['ab-split', 'cafe']);
		expect(document.querySelector('[data-show="disabled"]').getAttribute('aria-pressed')).toBe('true');
		expect(document.querySelector('[data-show="all"]').getAttribute('aria-pressed')).toBe('false');

		press('attention');
		expect(shown()).toEqual(['podcast']);
	});

	test('when nothing matches the page says so and offers the way back', () => {
		initExtensionsList();
		press('disabled');
		type('zzz');

		const empty = document.querySelector('.ext-no-match');
		expect(shown()).toEqual([]);
		expect(empty.hidden).toBe(false);
		expect(empty.querySelector('.ext-no-match-term').textContent).toBe('zzz');

		empty.querySelector('.ext-clear').click();

		expect(empty.hidden).toBe(true);
		expect(document.querySelector('.ext-search').value).toBe('');
		expect(shown()).toHaveLength(5);
	});

	test('Escape in the search box clears it', () => {
		initExtensionsList();
		type('pod');

		document.querySelector('.ext-search').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

		expect(shown()).toHaveLength(5);
	});

	test('the status filter is remembered, because enabling an extension reloads the page', () => {
		initExtensionsList();
		press('disabled');

		document.body.innerHTML = page();
		initExtensionsList();

		expect(shown()).toEqual(['ab-split', 'cafe']);
		expect(document.querySelector('[data-show="disabled"]').getAttribute('aria-pressed')).toBe('true');
	});

	test('a freshly installed extension is never filtered out of sight', () => {
		initExtensionsList();
		press('enabled');

		document.body.innerHTML = page();
		document.getElementById('ext-cafe').classList.add('ext-installed');
		initExtensionsList();

		expect(shown()).toContain('cafe');
	});

	test('a page with no toolbar, such as one with no extensions, is left alone', () => {
		document.body.innerHTML = '<p>No extensions installed.</p>';

		expect(() => initExtensionsList()).not.toThrow();
	});
});
