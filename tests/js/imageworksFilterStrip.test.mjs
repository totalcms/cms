import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { initFilterStrip } from '../../javascript/imageworks-filter-strip.js';

//-----------------------------------------------
// Admin ImageWorks filter strip: the tiles write the hidden `filt` input the
// preview URL is built from, and show the `duo` text field only for duotone.
//-----------------------------------------------

const render = (filt = '', duo = '') => {
	const chain = filt.split(',');
	const tileHtml = (name, group) =>
		`<button type="button" class="filter-tile" data-filter="${name}" data-group="${group}" aria-pressed="${chain.includes(name)}"></button>`;
	document.body.innerHTML = `
		<div id="filter-strip">
			<input type="hidden" name="filt" value="${filt}">
			${tileHtml('noir', 'look')}
			${tileHtml('ember', 'look')}
			${tileHtml('duotone', 'duotone')}
			${tileHtml('vignette', 'effect')}
			${tileHtml('grain', 'effect')}
			${tileHtml('glow', 'effect')}
		</div>
		<div id="filter-duo" ${chain.includes('duotone') ? '' : 'hidden'}>
			<input type="text" name="duo" value="${duo}" ${chain.includes('duotone') ? '' : 'disabled'}>
		</div>`;
	initFilterStrip(document.getElementById('filter-strip'));
};

const input = name => document.querySelector(`input[name="${name}"]`);
const tile = name => document.querySelector(`[data-filter="${name}"]`);
const duoBox = () => document.getElementById('filter-duo');

describe('filter strip', () => {
	beforeEach(() => render());

	it('writes the chosen filter and clears it on a second click', () => {
		tile('noir').click();
		expect(input('filt').value).toBe('noir');
		tile('noir').click();
		expect(input('filt').value).toBe('');
	});

	it('shows and enables the duo field only while duotone is chosen', () => {
		expect(duoBox().hidden).toBe(true);
		expect(input('duo').disabled).toBe(true);

		tile('duotone').click();
		expect(duoBox().hidden).toBe(false);
		expect(input('duo').disabled).toBe(false);

		tile('noir').click();
		expect(duoBox().hidden).toBe(true);
		expect(input('duo').disabled).toBe(true);
	});

	it('keeps what was typed in the duo field when switching away and back', () => {
		tile('duotone').click();
		input('duo').value = 'palette4,palette0';
		tile('noir').click();
		tile('duotone').click();
		expect(input('duo').value).toBe('palette4,palette0');
	});

	it('leaves duo out of the form while another filter is chosen', () => {
		render('noir', '112233,ffeedd');
		const form = document.createElement('form');
		form.append(duoBox());
		expect(new FormData(form).has('duo')).toBe(false);
	});

	it('keeps a duo the page opened with', () => {
		render('duotone', 'aabbcc,ddeeff');
		expect(input('duo').value).toBe('aabbcc,ddeeff');
		expect(input('duo').disabled).toBe(false);
	});

	it('replaces one look with another', () => {
		tile('noir').click();
		tile('ember').click();
		expect(input('filt').value).toBe('ember');
		expect(tile('noir').getAttribute('aria-pressed')).toBe('false');
	});

	it('stacks an effect on top of the look, after it in the chain', () => {
		tile('vignette').click();
		tile('ember').click();
		expect(input('filt').value).toBe('ember,vignette');
		expect(tile('ember').getAttribute('aria-pressed')).toBe('true');
		expect(tile('vignette').getAttribute('aria-pressed')).toBe('true');
	});

	it('keeps the effect when the look changes or is cleared', () => {
		tile('ember').click();
		tile('vignette').click();
		tile('noir').click();
		expect(input('filt').value).toBe('noir,vignette');
		tile('noir').click();
		expect(input('filt').value).toBe('vignette');
	});

	it('toggles an effect off on a second click', () => {
		tile('ember').click();
		tile('vignette').click();
		tile('vignette').click();
		expect(input('filt').value).toBe('ember');
		expect(tile('vignette').getAttribute('aria-pressed')).toBe('false');
	});

	it('stacks an effect on a duotone and keeps the duo field', () => {
		tile('duotone').click();
		tile('vignette').click();
		expect(input('filt').value).toBe('duotone,vignette');
		expect(duoBox().hidden).toBe(false);
	});

	it('stops at three filters, the most a chain takes', () => {
		tile('ember').click();
		tile('vignette').click();
		tile('grain').click();
		tile('glow').click();
		expect(input('filt').value).toBe('ember,vignette,grain');
		expect(tile('glow').getAttribute('aria-pressed')).toBe('false');
	});

	it('picks up a chain the page opened with', () => {
		render('ember,vignette');
		tile('noir').click();
		expect(input('filt').value).toBe('noir,vignette');
	});
});
