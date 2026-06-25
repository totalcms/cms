import MultiCheckboxField from '../../javascript/totalform/multicheckbox.js';

function mount(checked = [false, false]) {
	const el = document.createElement('div');
	el.className = 'form-field choice-field--checklist';
	el.innerHTML = `
		<fieldset>
			<legend>Tags</legend>
			<button type="button" class="choice-field-toggle-all">+</button>
			<div class="checkbox"><input type="checkbox" value="a"${checked[0] ? ' checked' : ''}></div>
			<div class="checkbox"><input type="checkbox" value="b"${checked[1] ? ' checked' : ''}></div>
		</fieldset>`;
	return el;
}

test('toggle-all checks all when not all checked, then unchecks all', () => {
	const el = mount([true, false]);
	const field = Object.create(MultiCheckboxField.prototype);
	field.container = el;
	field.changed = () => {};
	field.initToggleAll();

	el.querySelector('.choice-field-toggle-all').click();
	expect([...el.querySelectorAll('input')].every(cb => cb.checked)).toBe(true);

	el.querySelector('.choice-field-toggle-all').click();
	expect([...el.querySelectorAll('input')].some(cb => cb.checked)).toBe(false);
});
