import TotalField from "./totalfield";
import { isNestedField } from "./fieldCollection.mjs";

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

        // Sub-field edits propagate up via `subfield-change` (TotalField fires
        // it from changed() when isSubField() is true). Same pattern deck.js,
        // image.js, and file.js use. Ignore events the card itself dispatched:
        // when the card is nested (card-in-card, card-in-deck-item), its own
        // changed() emits subfield-change from this.container, and this
        // listener (also on this.container) would catch it and recurse,
        // re-marking the card as unsaved on every cycle.
        this.container.addEventListener("subfield-change", e => {
            if (e.target === this.container) return;
            this.changed();
        });

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
    // The card's own sub-fields render inside its `.card-fields` wrapper
    // (built by CardField PHP via FormGridBuilder::renderLayout). Walk only
    // form-fields whose nearest `.card-fields` ancestor is this card's
    // wrapper — that excludes:
    //   - composite child internals (image alt/featured/focalpoint, file
    //     name/tags, palette colors): they sit inside their parent's own
    //     form-field, which is the direct child
    //   - nested deck items' fields: those are inside `.deck-item` inside
    //     a nested deck's own form-field
    //   - nested card sub-fields if a card ever nests another card: those
    //     belong to the inner `.card-fields`, not this one
    //-------------------------
    subFields() {
        const cardFields = this.container.querySelector('.card-fields');
        if (!cardFields) return [];

        const fields = [];
        cardFields.querySelectorAll('.form-field').forEach(el => {
            if (el.closest('.card-fields') !== cardFields) return; // belongs to a nested card
            // Skip a composite child's internal sub-fields — an image's
            // alt/name/focalpoint, a file's name/ext, a nested deck item's fields.
            // They live inside their parent's own .form-field (which is in this
            // card's .card-fields), so the check above doesn't catch them; without
            // this guard they flatten into the card and collide with its own
            // properties (e.g. a file's `name` clobbering the card's `name`).
            if (isNestedField(el, cardFields)) return;
            if (!el.totalfield) return;
            fields.push(el.totalfield);
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

    //-------------------------
    // Delegate dirty-state to sub-fields so each TotalField's own
    // isUnsaved/saved logic runs (decks recursively clear their items, image/
    // file fields know about their queued uploads, etc). A blanket DOM sweep
    // of `.unsaved` would also clear classes on elements those sub-fields
    // legitimately want to keep tracking.
    //-------------------------
    isUnsaved() {
        if (this.container.classList.contains("unsaved")) return true;
        return this.subFields().some(field => field.isUnsaved());
    }

    saved() {
        super.saved();
        this.subFields().forEach(field => field.saved());
    }

    schema() {
        return {
            type: "card",
            fieldset: this.type,
            schemaref: this.schemaref
        };
    }
}
