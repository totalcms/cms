import DepotField from '../../javascript/totalform/depot.js';
import DepotDroplet from '../../javascript/totalform/droplet-depot.js';
import DropletTestSet from '../../javascript/totalform/droplet-testset.js';

//-----------------------------------------------
// A depot limited to PDFs rejected a .docx with an error, but the rejected
// file stayed in the browser with the trash button off, and its message
// stayed on the field's input, so every save failed until a reload.
//-----------------------------------------------

function buildDroplet() {
	const container = document.createElement('div');
	const input = document.createElement('input');
	input.type = 'text';
	input.reportValidity = vi.fn();
	container.appendChild(input);

	const droplet = Object.create(DepotDroplet.prototype);
	droplet.container = container;
	droplet.field = { input };
	droplet.testSet = new DropletTestSet({ filetype: ['application/pdf'] });
	droplet.dropzone = { removeFile: vi.fn() };

	return { droplet, input };
}

function fileOf(type, name) {
	const file = { type, name, size: 10 };
	file.acceptFile = vi.fn();
	file.rejectFile = vi.fn();
	return file;
}

describe('DepotDroplet rejected files', () => {
	test('a file that fails a rule is rejected and removed from the depot', () => {
		const { droplet, input } = buildDroplet();
		const file = fileOf('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'notes.docx');

		droplet.processTestSet(file);

		expect(file.rejectFile).toHaveBeenCalled();
		expect(input.reportValidity).toHaveBeenCalled();
		expect(droplet.dropzone.removeFile).toHaveBeenCalledWith(file);
	});

	test('a file that passes stays in the depot', () => {
		const { droplet } = buildDroplet();
		const file = fileOf('application/pdf', 'spec.pdf');

		droplet.processTestSet(file);

		expect(file.acceptFile).toHaveBeenCalled();
		expect(droplet.dropzone.removeFile).not.toHaveBeenCalled();
	});
});

describe('DepotField validate', () => {
	test('a rejected file no longer blocks saving the record', () => {
		const container = document.createElement('div');
		container.className = 'form-field';
		const input = document.createElement('input');
		input.type = 'text';
		container.appendChild(input);
		document.body.appendChild(container);

		const depot = Object.create(DepotField.prototype);
		depot.container = container;
		depot.input = input;
		depot.property = 'depot';
		depot.error = vi.fn();

		input.setCustomValidity('notes.docx: File type is not allowed');

		expect(depot.validate()).toBe(true);
		expect(input.validationMessage).toBe('');
	});
});
