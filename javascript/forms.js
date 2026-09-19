import TotalFormManager from './totalform/totalform-manager';
import TotalForm from './totalform/totalform';
import TotalField from './totalform/totalfield';
import SimpleForm from './totalform/simpleform';
import { coreFieldTypes } from './totalform/field-types-core';
import { lazyFieldTypes } from './totalform/field-types-lazy';

//-----------------------------------------------
// forms.js — the form runtime for a public page.
//
// cms.form.builder() on a public page used to need the whole admin bundle,
// because the form script lived there with every field editor the dashboard
// can show. This entry carries the form runtime and the light field classes,
// and loads a heavy field's module (Tiptap, Dropzone, CodeMirror, Choices)
// only when a form on the page renders that field. It ships as the `forms`
// core frontend feature through cms.assetsHead() / cms.assetsBody().
//
// The same window.TotalCMS surface as admin.js, so an extension's field type
// registers the same way on both surfaces.
//-----------------------------------------------

TotalForm.registerBuiltInFieldTypes({ ...coreFieldTypes, ...lazyFieldTypes });

window.TotalCMS = Object.assign(window.TotalCMS ?? {}, {
	TotalForm,
	TotalField,
	registerFieldType: (type, ctor) => TotalForm.registerFieldType(type, ctor),
});

// A customer admin page can carry both helpers. admin.js boots the form
// manager itself and registers every field class, so this entry stands down
// when it is present. admin.js sets its flag at module top level and module
// scripts run before DOMContentLoaded whatever their order, so checking at
// boot time is enough. The same guard against this entry loading twice.
function boot() {
	if (globalThis.__tcmsAdminLoaded === true || globalThis.__tcmsFormsBooted === true) return;
	globalThis.__tcmsFormsBooted = true;

	new TotalFormManager();
	Array.from(document.getElementsByClassName('simple-form')).forEach(form => new SimpleForm(form));
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', boot);
} else {
	boot();
}
