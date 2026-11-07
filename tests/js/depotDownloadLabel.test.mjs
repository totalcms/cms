import DepotField from '../../javascript/totalform/depot.js';

//-----------------------------------------------
// A public upload is stored under a random prefix; the admin labels the file
// with `download`, the name the visitor sent. It is visitor text, so it goes
// in through textContent.
//-----------------------------------------------

describe('DepotField labels', () => {
	test('a freshly uploaded row is labeled with the download name', () => {
		const depot = Object.create(DepotField.prototype);
		depot.setFileAttribute = vi.fn();
		depot.setFileLinksUrl = vi.fn();
		const row = document.createElement('div');
		row.innerHTML = '<div class="file"></div><div class="size"></div>';

		depot.updateNewFileMeta({ previewElement: row }, { name: 'abc123-report.pdf', download: '<b>report</b>.pdf', size: 2048 });

		const label = row.querySelector('.file');
		expect(label.textContent).toBe('<b>report</b>.pdf');
		expect(label.querySelector('b')).toBeNull();
	});

	test('a row without a download name keeps the stored name', () => {
		const depot = Object.create(DepotField.prototype);
		depot.setFileAttribute = vi.fn();
		depot.setFileLinksUrl = vi.fn();
		const row = document.createElement('div');
		row.innerHTML = '<div class="file"></div><div class="size"></div>';

		depot.updateNewFileMeta({ previewElement: row }, { name: 'plain.pdf', size: 2048 });

		expect(row.querySelector('.file').textContent).toBe('plain.pdf');
	});

	test('the preview heading shows the download name', () => {
		const depot = Object.create(DepotField.prototype);
		const attrs = { name: 'abc123-report.pdf', download: 'report.pdf', comments: '', size: 10, count: 0, uploadDate: '2026-01-01', tags: [] };
		depot.getFileAttribute = (_f, key) => attrs[key];
		depot.bytesToString = () => '10 B';
		depot.isPreviewable = () => false;
		depot.enableActionBarButtons = vi.fn();
		depot.previewButton = {};
		depot.folderPreview = document.createElement('div');
		depot.filePreview = document.createElement('div');
		depot.filePreview.innerHTML = '<h4 class="file-name"></h4><span class="file-comments"></span><span class="file-download"></span><span class="file-count"></span><span class="file-size"></span><span class="file-date"></span><div class="file-tags"></div><div class="file-icon"></div>';

		depot.updateFilePreview({});

		expect(depot.filePreview.querySelector('.file-name').textContent).toBe('report.pdf');
	});
});
