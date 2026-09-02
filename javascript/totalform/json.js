import TotalField from './totalfield';

// A complete, legal JSON escape — \uXXXX or one of the single-character
// escapes JSON allows after a backslash.
const LEGAL_ESCAPE = /\\(?:u[0-9a-fA-F]{4}|["\\\/bfnrt])/;

//-----------------------------------------------
// Forgive the JSON mistakes people actually make in this field.
//
// The box holds JSON Schema fragments, and the common one is a regex — which
// is nearly all backslashes: {"pattern": "^\d+\.\d+\.\d+$"}. JSON only allows
// a backslash before " \ / b f n r t u, so \d and \. are hard parse errors
// ("Bad escaped character") even though the intent is obvious.
//
// The alternation is ordered, so at each backslash a COMPLETE legal escape is
// matched first and passed through untouched; only a backslash that starts no
// legal escape falls through to the second branch and gets doubled. That makes
// the repair idempotent — already-correct \\d stays \\d rather than becoming a
// literal backslash — which matters because this runs on every failed parse.
//
// Trailing commas are handled here too; they used to be stripped from every
// value, valid or not.
//-----------------------------------------------
export function repairJson(value) {
	return value
		.replaceAll(new RegExp(`${LEGAL_ESCAPE.source}|\\\\`, 'g'), match => (match === '\\' ? '\\\\' : match))
		.replaceAll("\n", "")
		.replaceAll(/,\s*\}/g, "}")
		.replaceAll(/,\s*\]/g, "]");
}

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

        // Create container for CM6 (it needs a parent element, not a textarea)
        const editorContainer = document.createElement('div');
        editorContainer.className = 'totalform-json-editor-container';
        this.input.style.display = 'none';
        this.input.parentNode.insertBefore(editorContainer, this.input.nextSibling);

        editorContainer.style.height = height + 'px';

        this.editor = window.TotalCMSCodeMirror.createJsonEditor(editorContainer, {
            value: this.input.value || '',
            placeholder: this.input.getAttribute('placeholder') || '',
            matchBrackets: true,
            autoCloseBrackets: true,
            lineWrapping: true,
        });

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
        const value = this.editor ? this.editor.getValue() : this.input.value;
        if (value.trim().length === 0) return "";

        // Parse the value as typed first, so valid JSON is never rewritten —
        // the repair pass is lossy (it strips newlines) and has no business
        // touching input that already parses. Only a failure earns a repair.
        try {
            return JSON.parse(value);
        } catch {
            // Let this throw if the repair didn't rescue it: TotalForm.save()
            // reports the parse error rather than hanging, and swallowing it
            // here would save an empty value over the user's input.
            return JSON.parse(repairJson(value));
        }
    }

    changed() {
        this.container.classList.remove("error");
        this.input.setCustomValidity("");
        this.container.classList.add("unsaved");

        if (this.isSubField()) {
            this.dispatcher.dispatchEvent("subfield-change", { field: this });
            return;
        }
        this.dispatcher.dispatchEvent("field-change", { field: this });
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
