import DepotField from '../../javascript/totalform/depot.js';

//-----------------------------------------------
// The "add folder" dialog is prefilled with the selected folder's path plus a
// trailing slash ("de/"). Submitting it unchanged made addFolderToBrowser()
// split "de/" into ["de", ""] and insert a folder named "" — which the next
// form save wrote into the record. Deleting that folder then built
// `DELETE …/{property}/?path=de`; the trailing-slash middleware turned that
// into the whole-property DELETE route, and the entire depot was wiped.
//-----------------------------------------------

function buildDepot() {
	const container = document.createElement('div');
	container.innerHTML = `
		<template class="folder-template"><li><details><summary class="folder"></summary><ul class="folder-contents"></ul></details></li></template>
		<ul class="browser">
			<li><details><summary class="folder" data-path="de">de</summary><ul class="folder-contents"></ul></details></li>
		</ul>`;
	document.body.appendChild(container);

	const depot = Object.create(DepotField.prototype);
	depot.container = container;
	depot.browser = container.querySelector('.browser');
	depot.property = 'depot';
	depot.form = { collection: 'documents', id: 'doc-1', api: { postAPI: vi.fn(() => Promise.resolve()) } };
	depot.initBrowser = vi.fn();
	depot.resetPreview = vi.fn();

	return { depot, container };
}

describe('DepotField blank folder names', () => {
	test('addFolderToBrowser ignores the blank segment of a trailing slash', () => {
		const { depot } = buildDepot();

		depot.addFolderToBrowser('de/');
		depot.addFolderToBrowser('de//x');

		const names = Array.from(depot.browser.querySelectorAll('.folder')).map(f => f.textContent);
		expect(names).not.toContain('');
		expect(names.sort()).toEqual(['de', 'x']);
		expect(depot.browser.querySelector('[data-path="de/x"]')).not.toBeNull();
	});

	test('trashFolder never sends a delete for a blank-named folder', () => {
		const { depot } = buildDepot();
		const blank = document.createElement('summary');
		blank.className = 'folder';
		blank.textContent = '';
		depot.getParentPath = () => 'de';

		// window.prompt returns "" when the user just presses OK — which used
		// to match the blank name and confirm the delete.
		const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('');
		const alertSpy  = vi.spyOn(window, 'alert').mockImplementation(() => {});

		depot.trashFolder(blank);

		expect(depot.form.api.postAPI).not.toHaveBeenCalled();
		promptSpy.mockRestore();
		alertSpy.mockRestore();
	});

	test('trashFile never sends a delete for a blank file name', async () => {
		const { depot } = buildDepot();
		depot.getFileAttribute = () => '';
		depot.getPath = () => 'de';

		await depot.trashFile(document.createElement('li'));

		expect(depot.form.api.postAPI).not.toHaveBeenCalled();
	});
});
