import DepotField from '../../javascript/totalform/depot.js';

//-----------------------------------------------
// A depot renders ONE shared .folder-edit-dialog node for the whole field, and
// actionEditFolder() re-uses it for whichever folder is selected. Dialog caches
// its instance on the node (`if (dialog.dialog) return dialog.dialog`), so a
// second `new Dialog(node, ...)` hands back the FIRST instance — with the first
// call's onClose closure still holding the first folder. Renaming any folder
// after the first therefore renamed the first folder instead.
//-----------------------------------------------

function buildDepot() {
	const container = document.createElement('div');
	container.innerHTML = `
		<dialog class="folder-edit-dialog">
			<div class="form-field"><input name="name"></div>
			<button class="close"></button>
		</dialog>`;
	document.body.appendChild(container);

	const dialogEl = container.querySelector('.folder-edit-dialog');
	dialogEl.showModal = vi.fn();
	dialogEl.close = vi.fn();

	const depot = Object.create(DepotField.prototype);
	depot.container = container;
	depot.renameFolder = vi.fn();

	return { depot, container, dialogEl, nameInput: container.querySelector('[name=name]') };
}

function folder(path) {
	const summary = document.createElement('summary');
	summary.className = 'folder';
	summary.dataset.path = path;
	summary.textContent = path.split('/').pop();
	return summary;
}

// Open the shared dialog for a folder, type a new name, then close it.
function renameVia(depot, dialogEl, nameInput, target, newName) {
	depot.actionEditFolder(target);
	nameInput.value = newName;
	dialogEl.dialog.close();
}

describe('DepotField.actionEditFolder', () => {
	test('renames the folder that is currently selected, not the first one ever edited', () => {
		const { depot, dialogEl, nameInput } = buildDepot();
		const outer = folder('one');
		const inner = folder('one/two');

		renameVia(depot, dialogEl, nameInput, inner, 'two-renamed');
		expect(depot.renameFolder).toHaveBeenCalledWith(inner, 'two-renamed');

		depot.renameFolder.mockClear();
		renameVia(depot, dialogEl, nameInput, outer, 'one-renamed');
		expect(depot.renameFolder).toHaveBeenCalledWith(outer, 'one-renamed');
	});

	test('unchanged name is a no-op for the current folder', () => {
		const { depot, dialogEl, nameInput } = buildDepot();
		const inner = folder('one/two');
		const outer = folder('one');

		renameVia(depot, dialogEl, nameInput, inner, 'two-renamed');
		depot.renameFolder.mockClear();

		// "one" left untouched — the stale closure compared against "two" and fired anyway
		renameVia(depot, dialogEl, nameInput, outer, 'one');
		expect(depot.renameFolder).not.toHaveBeenCalled();
	});
});

//-----------------------------------------------
// renameFolder() shows the new name before the server answers. When the PUT
// failed (TOTALCMS-DASHBOARD-5K: a 404 from a route the catch-all swallowed),
// the rejection went unhandled, the operator saw no error, and the folder kept
// the new name on screen while it had the old one on disk.
//-----------------------------------------------

function buildRenamableDepot(postAPI) {
	const browser = document.createElement('ul');
	browser.innerHTML = `
		<li><details><summary class="folder" data-path="docs/old">old</summary>
			<ul><li><details><summary class="folder" data-path="docs/old/inner">inner</summary></details></li></ul>
		</details></li>`;
	document.body.appendChild(browser);

	const depot = Object.create(DepotField.prototype);
	depot.browser  = browser;
	depot.property = 'depot';
	depot.saved    = vi.fn();
	depot.form     = { collection: 'files', id: 'store', isEditMode: () => true, api: { postAPI } };

	return { depot, outer: browser.querySelector('[data-path="docs/old"]'), inner: browser.querySelector('[data-path="docs/old/inner"]') };
}

describe('DepotField.renameFolder', () => {
	afterEach(() => vi.restoreAllMocks());

	test('a failed rename puts the old name and paths back and says why', async () => {
		const failure = Object.assign(new Error('Folder not found'), { data: {}, status: 404 });
		const postAPI = vi.fn(() => Promise.reject(failure));
		const alert   = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const { depot, outer, inner } = buildRenamableDepot(postAPI);

		depot.renameFolder(outer, 'new');
		expect(outer.textContent).toBe('new');
		expect(inner.dataset.path).toBe('docs/new/inner');

		await vi.waitFor(() => expect(alert).toHaveBeenCalledWith('Folder not found'));
		expect(outer.textContent).toBe('old');
		expect(outer.dataset.path).toBe('docs/old');
		expect(inner.dataset.path).toBe('docs/old/inner');
		expect(depot.saved).not.toHaveBeenCalled();
	});

	test('sends the old path encoded, so a folder name with & or # reaches the server whole', () => {
		const postAPI = vi.fn(() => Promise.resolve({}));
		const { depot, outer } = buildRenamableDepot(postAPI);
		outer.dataset.path = 'docs/R&D #2';

		depot.renameFolder(outer, 'Research');

		expect(postAPI.mock.calls[0][0]).toBe('/collections/files/store/depot/folder/rename?path=docs%2FR%26D%20%232');
	});
});
