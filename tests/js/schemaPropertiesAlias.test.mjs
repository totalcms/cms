// Regression test: a stored field type of "multicheckbox" (legacy alias) must
// select the "checklist" option in the field-type dropdown, not fall back to
// the first option. This guards against data corruption when saving a schema
// whose property pre-dates the multicheckbox → checklist rename.

import SchemaPropertiesField from '../../javascript/totalform/schemaProperties.js';

/**
 * Build a minimal DOM that mirrors the schema-field template used by
 * SchemaPropertiesField.overrideProperty(). Only the parts exercised by the
 * alias path are needed: a [name=field] select and a [name=type] select.
 *
 * The select has "checklist" but NOT "multicheckbox", matching production.
 */
function mountSchemaField() {
	const container = document.createElement('div');
	container.className = 'schema-properties';

	// Template element (required by SchemaPropertiesField constructor)
	const template = document.createElement('template');
	template.className = 'schema-template';

	// Build the schema-field clone that will be inserted by overrideProperty
	const fieldDiv = document.createElement('div');
	fieldDiv.className = 'schema-field';

	// Property name input
	const nameInput = document.createElement('input');
	nameInput.type = 'text';
	fieldDiv.appendChild(nameInput);

	// Field-type select — has "checklist" but NOT "multicheckbox"
	const fieldSelect = document.createElement('select');
	fieldSelect.name = 'field';
	for (const val of ['code', 'checklist', 'text']) {
		const opt = document.createElement('option');
		opt.value = val;
		fieldDiv.appendChild(fieldSelect);
		fieldSelect.appendChild(opt);
	}
	fieldDiv.appendChild(fieldSelect);

	// Type select (required by overrideProperty branch)
	const typeSelect = document.createElement('select');
	typeSelect.name = 'type';
	const typeOpt = document.createElement('option');
	typeOpt.value = 'array';
	typeSelect.appendChild(typeOpt);
	fieldDiv.appendChild(typeSelect);

	template.content.appendChild(fieldDiv);
	container.appendChild(template);

	// Add button (required as insertion anchor)
	const addButton = document.createElement('button');
	addButton.className = 'cms-add';
	container.appendChild(addButton);

	document.body.appendChild(container);
	return container;
}

test('overrideProperty with field:"multicheckbox" selects the "checklist" option', () => {
	const container = mountSchemaField();

	// Instantiate with minimal settings — stub newField / scrollToField
	const spf = Object.create(SchemaPropertiesField.prototype);
	spf.container    = container;
	spf.fieldClass   = 'schema-field';
	spf.template     = container.querySelector('.schema-template');
	spf.addButton    = container.querySelector('.cms-add');
	spf.newField     = () => {};
	spf.scrollToField = () => {};

	const definition = { field: 'multicheckbox', type: 'array', label: 'Tags' };
	spf.overrideProperty('tags', definition);

	const insertedField = container.querySelector('.schema-field');
	const fieldSelect   = insertedField.querySelector('[name=field]');

	// Must resolve to "checklist", NOT fall back to first option ("code")
	expect(fieldSelect.value).toBe('checklist');
});

test('overrideProperty with field:"checklist" selects the "checklist" option', () => {
	document.body.innerHTML = '';
	const container = mountSchemaField();

	const spf = Object.create(SchemaPropertiesField.prototype);
	spf.container     = container;
	spf.fieldClass    = 'schema-field';
	spf.template      = container.querySelector('.schema-template');
	spf.addButton     = container.querySelector('.cms-add');
	spf.newField      = () => {};
	spf.scrollToField = () => {};

	const definition = { field: 'checklist', type: 'array', label: 'Tags' };
	spf.overrideProperty('tags', definition);

	const insertedField = container.querySelector('.schema-field');
	const fieldSelect   = insertedField.querySelector('[name=field]');

	expect(fieldSelect.value).toBe('checklist');
});
