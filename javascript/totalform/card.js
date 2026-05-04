import TotalField from "./totalfield";

//-----------------------------------------------
// Total CMS Card Field
//
// A card is a single-instance deck — one nested object whose shape comes from
// another schema (referenced via `schemaref`). Sub-fields render inline in the
// parent form. On save, the card's value is the nested object built from those
// sub-fields, keyed by their property names.
//-----------------------------------------------
export default class CardField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.schemaref = container.dataset.schemaref || container.dataset.deckref || '';

        // Sub-field edits propagate up via `subfield-change` (TotalField fires it
        // from changed() when isSubField() is true). Without this listener the
        // card itself stays clean even when its children are dirty — same pattern
        // deck.js, image.js, and file.js use.
        this.container.addEventListener("subfield-change", () => this.changed());

        // Visibility lookups (`watch: enabled`) need to resolve against the card's
        // sub-fields, not the parent form's top-level fields. Defer until the form
        // is ready so all sub-field TotalField instances exist on their containers.
        if (this.form && this.form.form) {
            this.form.form.addEventListener('totalform:ready', () => this.initVisibility(), { once: true });
        }
    }

    initVisibility() {
        if (!this.form || !this.form.visibility) return;
        const fields = this.subFields();
        if (fields.length > 0) {
            this.form.visibility.initializeScope(this.container, fields);
        }
    }

    //-------------------------
    // Locate sub-fields scoped to this card.
    //
    // Only DIRECT card children count — i.e. fields whose nearest .form-field
    // ancestor is the card itself. Without this filter, composite child fields
    // (image, file, etc.) leak their internal sub-fields (alt, featured,
    // focalpoint inputs, palette colors) into the card's value, producing
    // junk like `{0: ..., 1: ..., palette: ...}` on save.
    //-------------------------
    subFields() {
        const fields = [];
        const containers = this.container.querySelectorAll('.form-field');
        containers.forEach(el => {
            if (el === this.container) return;
            const parentFormField = el.parentElement?.closest('.form-field');
            if (parentFormField !== this.container) return; // nested deeper than direct child
            if (el.totalfield) fields.push(el.totalfield);
        });
        return fields;
    }

    //-------------------------
    // Card value is the nested object of sub-field values, keyed by property name.
    // Empty string values are kept (consumers can decide whether to omit them).
    //-------------------------
    getValue() {
        const value = {};
        this.subFields().forEach(sub => {
            if (!sub.property) return;
            value[sub.property] = sub.getValue();
        });
        return value;
    }

    //-------------------------
    // Setting a card value populates each sub-field from the given object.
    //-------------------------
    setValue(value) {
        if (!value || typeof value !== 'object') return;
        this.subFields().forEach(sub => {
            if (!sub.property || !(sub.property in value)) return;
            sub.setValue(value[sub.property]);
        });
        this.changed();
    }

    schema() {
        return {
            type: "card",
            fieldset: this.type,
            schemaref: this.schemaref
        };
    }
}
