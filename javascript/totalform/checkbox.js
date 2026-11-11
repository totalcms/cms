import TotalField from './totalfield';
import { t } from "../i18n";

//-----------------------------------------------
// Total CMS Checkbox Field
//-----------------------------------------------
export default class Checkbox extends TotalField {

    setValue(value) {
        this.input.checked = (value === true||value === "true"||value === 1);
        this.changed();
    }

    getValue() {
        return this.input.checked;
    }

	clearValue() {
		this.setValue(false);
	}

	validate() {
		if (!this.isVisible()) return true;
		this.input.setCustomValidity('');

		// For required checkboxes, validate that they are checked
		if (this.input.hasAttribute('required') && !this.input.checked) {
			this.input.setCustomValidity(t('form.must_be_checked', {}, 'This field must be checked.'));
		}

		// Call parent validation
		if (this.input.checkValidity()) return true;
		this.input.reportValidity();
		this.error(this.input.validationMessage);
		return false;
	}

    schema() {
        return {
            "type":"boolean",
            "fieldset":"checkbox"
        };
    }
}
