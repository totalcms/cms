import DepotField from '../../javascript/totalform/depot.js';

//-----------------------------------------------
// DepotField.getFolderData() recursively walks the depot's folder/file tree and
// serialises it (folders nest a `files` array; files become flat data objects;
// still-uploading items are skipped). getValue() wraps it. We drive the walk via
// Object.create + a built DOM tree.
//-----------------------------------------------

function fileItem(fields) {
	const item = document.createElement('div');
	for (const [name, value] of fields) {
		const fc = document.createElement('div');
		fc.className = 'form-field';
		const input = document.createElement('input');
		input.name = name;
		fc.appendChild(input);
		fc.totalfield = { getValue: () => value };
		item.appendChild(fc);
	}
	return item;
}

function folderItem(name, children) {
	const item = document.createElement('div');
	const details = document.createElement('details'); // firstChild === DETAILS => is_folder
	const label = document.createElement('summary');
	label.className = 'folder';
	label.textContent = name;
	details.appendChild(label);
	const contents = document.createElement('div');
	contents.className = 'folder-contents';
	children.forEach((c) => contents.appendChild(c));
	details.appendChild(contents);
	item.appendChild(details);
	return item;
}

function depotWith(browser) {
	const depot = Object.create(DepotField.prototype);
	depot.browser = browser;
	return depot;
}

describe('DepotField.getFolderData', () => {
	test('walks the tree: files become data objects, folders nest their files', () => {
		const browser = document.createElement('div');
		browser.appendChild(fileItem([['name', 'a.pdf'], ['size', 100]]));
		browser.appendChild(folderItem('Docs', [fileItem([['name', 'nested.zip'], ['size', 50]])]));

		expect(depotWith(browser).getFolderData(browser)).toEqual([
			{ name: 'a.pdf', size: 100 },
			{ name: 'Docs', mime: 'folder', files: [{ name: 'nested.zip', size: 50 }] },
		]);
	});

	test('skips files that are still uploading (dz-processing)', () => {
		const browser = document.createElement('div');
		browser.appendChild(fileItem([['name', 'done.pdf']]));
		const processing = fileItem([['name', 'uploading.pdf']]);
		processing.classList.add('dz-processing');
		browser.appendChild(processing);

		expect(depotWith(browser).getFolderData(browser)).toEqual([{ name: 'done.pdf' }]);
	});

	test('handles deeply nested folders recursively', () => {
		const browser = document.createElement('div');
		browser.appendChild(
			folderItem('L1', [folderItem('L2', [fileItem([['name', 'deep.txt']])])]),
		);

		expect(depotWith(browser).getFolderData(browser)).toEqual([
			{
				name: 'L1',
				mime: 'folder',
				files: [{ name: 'L2', mime: 'folder', files: [{ name: 'deep.txt' }] }],
			},
		]);
	});
});
