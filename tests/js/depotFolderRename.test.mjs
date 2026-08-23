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
