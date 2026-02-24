import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS JSON Field
//-----------------------------------------------
export default class JSONField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.editor = null;
        this.initializeCodeEditor();
    }

    initializeCodeEditor() {
        if (!window.TotalCMSCodeMirror) {
            setTimeout(() => this.initializeCodeEditor(), 100);
            return;
        }

        const rows = parseInt(this.input.getAttribute('rows')) || 5;
        const lineHeight = 20;
        const height = rows * lineHeight + 20;

        this.editor = window.TotalCMSCodeMirror.createJsonEditor(this.input, {
            matchBrackets: true,
            autoCloseBrackets: true,
            lineWrapping: true,
        });

        this.editor.setSize(null, height);

        // Sync editor content to textarea on change
        this.editor.on('change', () => {
            this.input.value = this.editor.getValue();
            this.changed();
        });

        // Refresh when the editor becomes visible (e.g. inside a dialog)
        const wrapper = this.editor.getWrapperElement();
        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) {
                this.editor.refresh();
            }
        });
        observer.observe(wrapper);

        setTimeout(() => this.editor.refresh(), 150);
    }

    setValue(value) {
        if (typeof value === 'object') {
            value = JSON.stringify(value, null, 2);
        }
        if (this.editor) {
            this.editor.setValue(value);
        } else {
            this.input.innerHTML = value;
        }
        this.changed();
    }

    getValue() {
        let value = this.editor ? this.editor.getValue() : this.input.value;
        // trim trailing commas for users from JSON string.
        value = value.replaceAll("\n", "")
            .replaceAll(/,\s*\}/g, "}")
            .replaceAll(/,\s*\]/g, "]");

        return value.length > 0 ? JSON.parse(value) : "";
    }

    validate() {
		if (!this.isVisible()) return true;

		// Sync editor content to textarea
		if (this.editor) {
			this.input.value = this.editor.getValue();
		}

		try {
            this.getValue();
            return true;
        } catch (e) {
            this.error("Invalid JSON format.");
            return false;
        }
	}
}
