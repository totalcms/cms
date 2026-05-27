import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Textarea Field
//
// Auto-grows the textarea to fit content as the user types. Implements
// "grow-only" semantics: if a desktop user drags the corner handle to
// make the textarea taller than the content needs, that manual size is
// preserved across subsequent edits. The textarea never auto-shrinks
// once expanded, avoiding layout jitter when content is deleted.
//
// Disable per-field by setting `"autoGrow": false` in the schema's
// settings object. Useful when an operator wants a fixed-height field
// (e.g., a short summary) with the internal scrollbar fallback.
//-----------------------------------------------
export default class Textarea extends TotalField {

	constructor(container, settings) {
		super(container, settings);

		if (this.settings.autoGrow !== false) {
			// Grow on construction so saved content is fully visible
			// from the moment the form renders.
			this.autoGrow();
			this.input.addEventListener('input', () => this.autoGrow());
		}
	}

	autoGrow() {
		// Only grow when content exceeds the current visible height.
		// scrollHeight < clientHeight means the user has manually dragged
		// the textarea taller than needed; preserve that.
		const needed = this.input.scrollHeight;
		if (needed > this.input.clientHeight) {
			this.input.style.height = needed + 'px';
		}
	}

	setValue(value) {
		this.input.innerHTML = value;
		if (this.settings.autoGrow !== false) {
			this.autoGrow();
		}
		this.changed();
	}

}
