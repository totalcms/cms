import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Password Field
//-----------------------------------------------
export default class PasswordField extends TotalField {

    validate() {
		if (!this.isVisible()) return true;
        const confirm = document.getElementById(this.input.id+"-confirm");

        if (this.input.value !== confirm.value) {
            confirm.setCustomValidity("Passwords do not match");
            confirm.reportValidity();
        }

		if (this.input.checkValidity() && confirm.checkValidity()) return true;

        if (!this.input.checkValidity()) this.error(this.input.validationMessage);
		if (!confirm.checkValidity()) this.error(confirm.validationMessage);

		return false;
	}
}
