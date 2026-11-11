import TotalField from './totalfield';
import { t } from "../i18n";

//-----------------------------------------------
// Total CMS Password Field
//-----------------------------------------------
export default class PasswordField extends TotalField {

    validate() {
		if (!this.isVisible()) return true;
        const confirm = document.getElementById(this.input.id+"-confirm");
		confirm.setCustomValidity('');
		this.input.setCustomValidity('');
		const errorMessage = t("form.passwords_mismatch", {}, "Passwords do not match.");
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
