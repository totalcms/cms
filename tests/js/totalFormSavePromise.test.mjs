import TotalForm from '../../javascript/totalform/totalform.js';

//-----------------------------------------------
// save() returns its promise. Nothing in the admin awaited it before; the
// WebMCP bridge answers an agent's submit with the form's own save result,
// so it needs the outcome — resolved with the API response, rejected with
// the reason — while every existing side effect (afterSave, error, the
// processing state) keeps happening exactly as before.
//-----------------------------------------------

function formUnderTest() {
	const form = Object.create(TotalForm.prototype);
	form.form = document.createElement('form');
	form.fields = [];
	form.route = '/collections/blog';
	form.method = 'POST';
	form.validate = vi.fn(() => true);
	form.isError = vi.fn(() => false);
	form.generateData = vi.fn(() => ({ title: 'Hello' }));
	form.closeDialog = vi.fn();
	form.processing = vi.fn();
	form.afterSave = vi.fn();
	form.error = vi.fn();
	form.api = { postAPI: vi.fn() };
	return form;
}

describe('TotalForm.save() returns its outcome', () => {
	test('resolves with the API response and still runs afterSave', async () => {
		const form = formUnderTest();
		form.api.postAPI.mockResolvedValue({ data: { id: 'hello' } });

		await expect(form.save()).resolves.toEqual({ data: { id: 'hello' } });

		expect(form.api.postAPI).toHaveBeenCalledWith('/collections/blog', { title: 'Hello' }, 'POST');
		expect(form.afterSave).toHaveBeenCalledWith({ data: { id: 'hello' } });
		expect(form.processing).toHaveBeenCalled();
	});

	test('rejects when the request fails and still reports the error', async () => {
		const form = formUnderTest();
		form.api.postAPI.mockRejectedValue(new Error('boom'));

		await expect(form.save()).rejects.toThrow('boom');

		expect(form.error).toHaveBeenCalledWith(expect.any(Error));
		expect(form.afterSave).not.toHaveBeenCalled();
	});

	test('rejects without sending when validation fails', async () => {
		const form = formUnderTest();
		form.validate.mockReturnValue(false);

		await expect(form.save()).rejects.toThrow(/validation/i);

		expect(form.api.postAPI).not.toHaveBeenCalled();
		expect(form.error).toHaveBeenCalled();
		expect(form.validated).toBe(false);
	});

	test('rejects without sending when a field cannot produce its value', async () => {
		const form = formUnderTest();
		form.generateData.mockImplementation(() => { throw new Error('bad JSON'); });

		await expect(form.save()).rejects.toThrow('bad JSON');

		expect(form.api.postAPI).not.toHaveBeenCalled();
		expect(form.error).toHaveBeenCalledWith('Cannot save: bad JSON');
	});
});
