//-----------------------------------------------
// forms.js, the public-page entry: publishes the extension surface on the
// TotalCMS global (which may already be the API client class from
// totalcms.js), registers the light and lazy field sets, boots one form
// manager, and stands down when admin.js is on the page.
//-----------------------------------------------

async function loadEntry({ adminPresent = false, existingGlobal = undefined } = {}) {
	vi.resetModules();
	document.body.innerHTML = '<form class="totalform" data-api="/api" data-route="/x" data-method="POST"><div class="form-field" data-type="text"><input name="a"></div></form>';
	delete globalThis.__tcmsFormsBooted;
	delete globalThis.__tcmsAdminLoaded;
	if (adminPresent) globalThis.__tcmsAdminLoaded = true;
	if (existingGlobal === undefined) delete window.TotalCMS;
	else window.TotalCMS = existingGlobal;
	await import('../../javascript/forms.js');
	return (await import('../../javascript/totalform/totalform.js')).default;
}

describe('forms.js', () => {
	test('publishes TotalForm, TotalField and registerFieldType, keeping a global that is already there', async () => {
		class ApiClient {}
		await loadEntry({ existingGlobal: ApiClient });

		expect(window.TotalCMS).toBe(ApiClient);
		expect(window.TotalCMS.registerFieldType).toBeTypeOf('function');
		expect(window.TotalCMS.TotalField).toBeTypeOf('function');
		expect(window.TotalCMS.TotalForm).toBeTypeOf('function');
	});

	test('registers the light classes and a loader for every heavy type', async () => {
		const TotalForm = await loadEntry();

		expect(TotalForm.builtInFieldTypes.text).toBeTypeOf('function');
		expect(TotalForm.builtInFieldTypes.styledtext.lazy).toBeTypeOf('function');
		expect(TotalForm.builtInFieldTypes.image.lazy).toBeTypeOf('function');
	});

	test('boots the form manager once, and builds the forms on the page', async () => {
		await loadEntry();

		expect(globalThis.__tcmsFormsBooted).toBe(true);
		expect(document.querySelector('form.totalform').totalform).toBeDefined();
		expect(document.getElementById('totalcms-status-banner')).not.toBeNull();
	});

	test('stands down when admin.js is on the page', async () => {
		await loadEntry({ adminPresent: true });

		expect(globalThis.__tcmsFormsBooted).toBeUndefined();
		expect(document.querySelector('form.totalform').totalform).toBeUndefined();
	});
});
