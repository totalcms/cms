import TotalField from './totalfield';
import { t } from "../i18n";

//-----------------------------------------------
// Total CMS Select Field
//-----------------------------------------------
export default class SelectField extends TotalField {

    constructor(container, settings) {
        super(...arguments);

		this.input.addEventListener("change", e => {
			this.input.querySelector("[disabled]")?.removeAttribute("selected");
		});

		if (window.navigator.userAgent.indexOf("MSIE") > 0 || window.navigator.userAgent.indexOf("Edge") > 0) {
			// IE Hack - select does not trigger input events. https://connect.microsoft.com/IE/feedback/details/1816207
			this.input.addEventListener("click", e => { this.changed() }, {once: true});
		}

		this.setupClearButton();
    }

	setupClearButton() {
		// Check if clearValue option is disabled
		const clearValue = this.settings.clearValue !== false;
		if (!clearValue) return;

		// Create clear button
		this.clearButton = document.createElement("button");
		this.clearButton.type = "button";
		this.clearButton.className = "select-clear-button";
		this.clearButton.innerHTML = "&times;";
		// The button shows only a ×, so the label is the tooltip and the
		// screen reader's text, in the page's language.
		const label = t("form.clear_selection", {}, "Clear selection");
		this.clearButton.title = label;
		this.clearButton.setAttribute("aria-label", label);

		// Add clear button to form group
		const formGroup = this.input.closest(".form-group");
		if (formGroup) {
			formGroup.appendChild(this.clearButton);
		}

		// Handle click
		this.clearButton.addEventListener("click", (e) => {
			e.preventDefault();
			e.stopPropagation();
			this.clearSelection();
		});

		this.input.addEventListener("change", () => this.updateClearButton());

		// Initial state
		this.updateClearButton();
	}

	clearSelection() {
		this.input.value = "";
		const options = Array.from(this.input.getElementsByTagName("option"));
		for (const option of options) {
			option.selected = false;
		}
		// Select the first disabled option if it exists (placeholder)
		const placeholder = this.input.querySelector("option[disabled]");
		if (placeholder) {
			placeholder.selected = true;
			placeholder.setAttribute("selected", "");
		}
		this.changed();
		this.updateClearButton();
	}

	updateClearButton() {
		if (!this.clearButton) return;

		const hasValue = this.input.value && this.input.value.trim() !== "";
		if (hasValue) {
			this.clearButton.classList.add("visible");
		} else {
			this.clearButton.classList.remove("visible");
		}
	}

    setValue(value) {
        this.input.value = value;
        // Select Options
        const options = Array.from(this.input.getElementsByTagName("option"));
        for (const option of options) {
			option.selected = (option.value.trim() === value.trim());
        }
        this.changed();
		this.updateClearButton();
    }

    schema() {
        return {
            "type":"string",
            "field":"select"
        };
    }
}
