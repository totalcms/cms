import { vi } from 'vite-plus/test';

//-----------------------------------------------
// Messages a visitor sees on a public form go through the JS catalog, so a
// Spanish page shows Spanish text. Without a catalog (the form builder loads
// none) they keep today's English wording.
//-----------------------------------------------

const TotalForm = (await import('../../javascript/totalform/totalform.js')).default;

function invalidForm() {
	const form = Object.create(TotalForm.prototype);
	form.fields = [];
	form.form = document.createElement('form');
	form.pending = new Set();
	form.validate = () => false;
	form.error = vi.fn();
	return form;
}

afterEach(() => {
	delete window.TCMS_TRANSLATIONS;
});

describe('form messages follow the catalog', () => {
	test('an invalid form rejects with the translated text', async () => {
		window.TCMS_TRANSLATIONS = { 'form.fix_errors': 'Corrija los errores antes de guardar.' };
		const form = invalidForm();

		await expect(form.save()).rejects.toThrow('Corrija los errores antes de guardar.');
		expect(form.error).toHaveBeenCalledWith('Corrija los errores antes de guardar.');
	});

	test('with no catalog it keeps the English text', async () => {
		const form = invalidForm();

		await expect(form.save()).rejects.toThrow('Please fix validation errors before saving.');
		expect(form.error).toHaveBeenCalledWith('Please fix validation errors before saving.');
	});

	test('the form-in-error message is translated', async () => {
		window.TCMS_TRANSLATIONS = { 'form.has_errors': 'Hay un error en el formulario.' };
		const form = invalidForm();
		form.validate = () => true;
		form.isError = () => true;

		await expect(form.save()).rejects.toThrow('Hay un error en el formulario.');
	});

	test('a payload that cannot be read shows the translated reason template', async () => {
		window.TCMS_TRANSLATIONS = { 'form.cannot_save': 'No se puede guardar: {reason}' };
		const form = invalidForm();
		form.validate = () => true;
		form.isError = () => false;
		form.generateData = () => { throw new Error('JSON no válido'); };

		await expect(form.save()).rejects.toThrow('JSON no válido');
		expect(form.error).toHaveBeenCalledWith('No se puede guardar: JSON no válido');
	});

	test('the cannot-save message keeps its English wording with no catalog', async () => {
		const form = invalidForm();
		form.validate = () => true;
		form.isError = () => false;
		form.generateData = () => { throw new Error('bad json'); };

		await expect(form.save()).rejects.toThrow('bad json');
		expect(form.error).toHaveBeenCalledWith('Cannot save: bad json');
	});
});

describe('field validation messages follow the catalog', () => {
	test('a password mismatch is translated', async () => {
		window.TCMS_TRANSLATIONS = { 'form.passwords_mismatch': 'Las contraseñas no coinciden.' };
		const Password = (await import('../../javascript/totalform/password.js')).default;
		const input = document.createElement('input');
		input.id = 'pw';
		input.value = 'a';
		const confirm = document.createElement('input');
		confirm.id = 'pw-confirm';
		confirm.value = 'b';
		confirm.reportValidity = () => true;
		document.body.append(input, confirm);
		const field = Object.create(Password.prototype);
		field.input = input;
		field.isVisible = () => true;
		field.error = vi.fn();

		expect(field.validate()).toBe(false);
		expect(confirm.validationMessage).toBe('Las contraseñas no coinciden.');
	});

	test('a required file names the label in the translated text', async () => {
		window.TCMS_TRANSLATIONS = { 'form.required_file': '{label} es obligatorio: suba un archivo' };
		const File = (await import('../../javascript/totalform/file.js')).default;
		const input = document.createElement('input');
		input.required = true;
		const field = Object.create(File.prototype);
		field.input = input;
		field.label = 'Currículum';
		field.property = 'cv';
		field.isVisible = () => true;
		field.hasFile = () => false;
		field.error = vi.fn();

		expect(field.validate()).toBe(false);
		expect(field.error).toHaveBeenCalledWith('Currículum es obligatorio: suba un archivo');
	});
});
