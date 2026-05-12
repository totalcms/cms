import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Secret Field
// Text field with password masking and a show/hide toggle
//-----------------------------------------------
export default class SecretField extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		// Turn the form-group-icon into a clickable toggle
		this.icon = this.container.querySelector('.form-group-icon');
		if (this.icon) {
			this.icon.style.cursor = 'pointer';
			this.icon.addEventListener('click', () => this.toggle());
		}
	}

	toggle() {
		const isPassword = this.input.type === 'password';
		this.input.type = isPassword ? 'text' : 'password';
		this.container.classList.toggle('secret-visible', isPassword);
	}

	schema() {
		return {
			"type"  : "string",
			"field" : "secret"
		};
	}
}
