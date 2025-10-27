import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Password Field
//-----------------------------------------------
export default class PasswordField extends TotalField {

    validate() {
		if (!this.isVisible()) return true;
        const confirm = document.getElementById(this.input.id+"-confirm");
		confirm.setCustomValidity('');
		this.input.setCustomValidity('');
		const errorMessage = "Passwords do not match.";
        if (this.input.value !== confirm.value) {
            confirm.setCustomValidity(errorMessage);
            confirm.reportValidity();
        }

		if (this.input.checkValidity() && confirm.checkValidity()) return true;

        if (!this.input.checkValidity()) this.error(this.input.validationMessage || errorMessage);
		if (!confirm.checkValidity()) this.error(confirm.validationMessage || errorMessage);

		return false;
	}
}
