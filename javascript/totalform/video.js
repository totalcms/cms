import CardField from "./card.js";

//-----------------------------------------------
// Total CMS Video Field
//
// An external video: the author pastes a URL, and provider/thumbnail/title
// metadata is derived server-side on save (see PropertyDataProcessor's video
// branch). The field also carries an optional uploaded poster image, wired up
// exactly like a card's nested image (single sub-field inside `.card-fields`),
// which is why this extends CardField — subFields()/isUnsaved()/saved() all
// need to see the poster the same way a real card sees its children.
//
// The poster field lives inside `.video-media`, a box in the stored aspect
// ratio that is also where the vendor thumbnail shows when no poster has been
// uploaded. Poster presence is read by CSS straight off the image field's
// own markup (`:has(.dz-preview:not(.not-found))`); this class only manages
// the vendor thumbnail layer and the chip that names it.
//-----------------------------------------------
export default class VideoField extends CardField {

    constructor(container, settings) {
        super(container, settings);

        this.urlInput         = container.querySelector(".video-url-input");
        this.providerInput    = container.querySelector(".video-provider-input");
        this.videoIdInput     = container.querySelector(".video-videoId-input");
        this.thumbnailInput   = container.querySelector(".video-thumbnail-input");
        this.titleInput       = container.querySelector(".video-title-input");
        this.aspectRatioInput = container.querySelector(".video-aspectRatio-input");
        this.providerBadge    = container.querySelector(".video-provider");
        this.titleBadge       = container.querySelector(".video-title");
        this.mediaContainer   = container.querySelector(".video-media");
        this.thumbnailChip    = container.querySelector(".video-media-chip.thumbnail");

        this.urlInput.addEventListener("input", () => this.onUrlInput());
    }

    // The poster is the video's one card-shaped sub-field (see subFields() on
    // CardField, inherited unchanged). Returns null before the poster's own
    // TotalField instance exists yet (e.g. during this constructor).
    posterField() {
        return this.subFields().find(field => field.property === "poster") ?? null;
    }

    // A change inside the poster is NOT a change to the video. In edit mode
    // the poster persists itself exactly like a top-level image field: the
    // upload POSTs straight onto the object, alt/focal-point edits PUT through
    // image.js autosave(), and the trash button DELETEs the nested child. So
    // the video's own baseline simply follows the poster's value instead of
    // flagging the video unsaved and demanding a redundant Save. Anything
    // the poster still owes (a queued upload on a not-yet-saved object) keeps
    // surfacing through CardField.isUnsaved(), which asks the sub-field.
    //
    // Events from the poster's own internals (alt, focal point) bubble
    // through here too, so the test is "originated inside the poster", not
    // "is the poster".
    onSubFieldChange(e) {
        const poster = this.posterField();
        if (poster && poster.container && poster.container.contains(e.target)) {
            this.onPosterChange(poster);
            return;
        }
        super.onSubFieldChange(e);
    }

    onPosterChange(poster) {
        if (this.storedValue && typeof this.storedValue === "object") {
            this.storedValue = { ...this.storedValue, poster: poster.getValue() };
        }

        // After a delete the image field clears its value but never calls
        // saved() on itself (nothing else is left to persist), which would
        // keep the video reporting unsaved through the sub-field. Nothing
        // pending, no image: it is saved.
        const pending  = poster.droplet && typeof poster.droplet.pendingFiles === "function" ? poster.droplet.pendingFiles().length : 0;
        const hasImage = typeof poster.hasImage === "function" ? poster.hasImage() : true;
        if (!hasImage && pending === 0 && typeof poster.saved === "function") {
            poster.saved();
        }

        this.updateMedia();
    }

    // A changed URL invalidates every value the server derives from it, so the
    // derived hidden inputs are blanked here rather than left stale until the
    // next save. `aspectRatio` is deliberately left alone — it renders the
    // responsive embed wrapper today and a mid-edit URL change shouldn't pop
    // the layout before a save re-derives it. The provider badge gets an
    // immediate client-side guess (host name only) so the input doesn't look
    // dead while the real provider/title/thumbnail wait for the next save.
    onUrlInput() {
        this.providerInput.value  = "";
        this.videoIdInput.value   = "";
        this.thumbnailInput.value = "";
        this.titleInput.value     = "";

        this.updateProviderBadge();
        this.updateMedia();
        this.changed();
    }

    updateProviderBadge() {
        const url = this.urlInput.value.trim();
        this.titleBadge.textContent = "";

        if (url === "") {
            this.providerBadge.textContent = "";
            return;
        }
        try {
            this.providerBadge.textContent = new URL(url).hostname;
        } catch {
            this.providerBadge.textContent = "";
        }
    }

    // Sync the media box's vendor-thumbnail layer with the hidden thumbnail
    // input: present → an <img> the CSS shows whenever no poster is in the
    // box; blank (URL just changed, nothing derived yet) → removed, so the
    // placeholder shows instead. The chip text is rebuilt from the
    // `data-thumbnail-label` template ("{provider} thumbnail") rather than
    // hard-coded English. Poster presence is mirrored onto `has-poster` for
    // anything that wants it, but the CSS reads the image field directly.
    updateMedia() {
        if (!this.mediaContainer) return;

        const thumbnail = this.thumbnailInput.value;
        const provider  = this.providerInput.value;
        const poster    = this.posterField();
        const hasPoster = !!(poster && typeof poster.hasImage === "function" && poster.hasImage());

        this.mediaContainer.classList.toggle("has-poster", hasPoster);
        this.mediaContainer.classList.toggle("has-thumbnail", thumbnail !== "");

        let img = this.mediaContainer.querySelector(".video-vendor-thumbnail");
        if (thumbnail === "") {
            if (img) img.remove();
        } else {
            if (!img) {
                img = document.createElement("img");
                img.className = "video-vendor-thumbnail";
                img.alt = "";
                img.draggable = false;
                img.addEventListener("contextmenu", event => event.preventDefault());
                this.mediaContainer.prepend(img);
            }
            if (img.getAttribute("src") !== thumbnail) img.src = thumbnail;
        }

        if (this.thumbnailChip) {
            const template = this.mediaContainer.dataset.thumbnailLabel || "{provider} thumbnail";
            const name     = provider ? provider.charAt(0).toUpperCase() + provider.slice(1) : "";
            this.thumbnailChip.textContent = template.replace("{provider}", name);
        }
    }

    getValue() {
        const poster = this.posterField();

        return {
            url         : this.urlInput.value.trim(),
            provider    : this.providerInput.value,
            videoId     : this.videoIdInput.value,
            thumbnail   : this.thumbnailInput.value,
            title       : this.titleInput.value,
            aspectRatio : this.aspectRatioInput.value,
            poster      : poster ? poster.getValue() : null,
        };
    }

    setValue(value) {
        if (!value || typeof value !== "object") return;

        this.urlInput.value         = value.url ?? "";
        this.providerInput.value    = value.provider ?? "";
        this.videoIdInput.value     = value.videoId ?? "";
        this.thumbnailInput.value   = value.thumbnail ?? "";
        this.titleInput.value       = value.title ?? "";
        this.aspectRatioInput.value = value.aspectRatio ?? "16:9";
        this.providerBadge.textContent = value.provider ?? "";
        this.titleBadge.textContent    = value.title ?? "";

        const poster = this.posterField();
        if (poster && value.poster) poster.setValue(value.poster);

        this.updateMedia();
        this.changed();
    }

    schema() {
        return {
            type     : "video",
            fieldset : this.type,
        };
    }
}
