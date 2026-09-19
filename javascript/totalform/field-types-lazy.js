//-----------------------------------------------
// The heavy field classes as loaders. forms.js registers these, so a public
// page downloads Tiptap, CodeMirror, Dropzone or Choices only when a form on
// it renders a field that needs them. TotalForm.loadField() resolves a
// loader once (the promise is cached on the marker) and constructs the field
// when its module arrives; save() waits for anything still loading.
//
// Types that share a module share a marker, so the module loads once.
//-----------------------------------------------
const lazy = (loader) => ({ lazy: loader });

const checklist     = lazy(() => import('./checklist.js'));
const localized     = lazy(() => import('./localizedtext.js'));

export const lazyFieldTypes = {
	checklist           : checklist,
	multicheckbox       : checklist,
	multiselect         : lazy(() => import('./multiselect.js')),
	list                : lazy(() => import('./list.js')),
	range               : lazy(() => import('./range.js')),
	price               : lazy(() => import('./price.js')),
	styledtext          : lazy(() => import('./styledtext.js')),
	localizedtext       : localized,
	localizedtextarea   : localized,
	localizedstyledtext : lazy(() => import('./localizedstyledtext.js')),
	svg                 : lazy(() => import('./svg.js')),
	image               : lazy(() => import('./image.js')),
	gallery             : lazy(() => import('./gallery.js')),
	json                : lazy(() => import('./json.js')),
	file                : lazy(() => import('./file.js')),
	depot               : lazy(() => import('./depot.js')),
	depotDrop           : lazy(() => import('./depot-drop.js')),
	code                : lazy(() => import('./code.js')),
	card                : lazy(() => import('./card.js')),
	video               : lazy(() => import('./video.js')),
	deck                : lazy(() => import('./deck.js')),
	deckTable           : lazy(() => import('./deckTable.js')),
	properties          : lazy(() => import('./properties.js')),
	customProperties    : lazy(() => import('./customProperties.js')),
	schemaProperties    : lazy(() => import('./schemaProperties.js')),
};
