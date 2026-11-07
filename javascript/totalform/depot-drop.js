import TotalField from "./totalfield";
import DropletTestSet from "./droplet-testset";
import Dropzone from "@deltablot/dropzone";
import { csrfHeaders } from "../csrf";
import { t } from "../i18n";

//-----------------------------------------------
// Total CMS Depot Drop Field (write-only upload)
//-----------------------------------------------
export default class DepotDropField extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.preview = this.container.querySelector(".total-preview");
        this.previewTemplate = this.container.querySelector("template.file-template").innerHTML;

        if (this.settings.rules && Object.keys(this.settings.rules).length > 0) {
            this.testSet = new DropletTestSet(this.settings.rules);
        }

        // The comment box (public depot drops, or a field set to `comments: true`)
        // and the tag menu (a field given `tags`). With either, a drop only queues
        // the files; the Send button starts the upload, because the comment and
        // tag have to exist before the upload does.
        this.comments    = this.settings.comments === true;
        this.commentText = this.container.querySelector(".depot-drop-comment-text");
        this.tagSelect   = this.container.querySelector(".depot-drop-tag");
        this.sendButton  = this.container.querySelector(".depot-drop-send");
        this.holdQueue   = this.comments || this.tagSelect !== null;
        this.batchComment = "";
        this.batchTag     = "";
        this.hadSuccess   = false;

        this.setupDropzone();
        this.setupCommentBox();
    }

    setupCommentBox() {
        if (!this.sendButton) return;
        this.sendButton.addEventListener("click", () => this.send());
    }

    /**
     * Start the held queue. An empty queue does nothing. The comment and tag are
     * fixed here, so every chunk of every file in the batch carries the same ones.
     */
    send() {
        if (this.dropzone.getQueuedFiles().length === 0) return;

        this.batchComment = this.commentText ? this.commentText.value : "";
        this.batchTag = this.tagSelect ? this.tagSelect.value : "";
        if (this.commentText) this.commentText.disabled = true;
        if (this.tagSelect) this.tagSelect.disabled = true;
        if (this.sendButton) this.sendButton.disabled = true;

        this.dropzone.options.autoProcessQueue = true;
        this.dropzone.processQueue();
    }

    /**
     * The fields added to every request, chunks included. They are appended in
     * the `sending` event, which fires per request: a `params` option would
     * REPLACE Dropzone's default, which adds dzuuid, dzchunkindex and the other
     * chunk fields, and the server would store every chunk as its own file.
     * The bot fields come first because the server checks them on each request.
     * A signed-in drop with no comment box or tag sends no meta, as before.
     */
    extraParams() {
        const params = this.botValues();
        const meta = {};

        if (this.comments) meta.comments = this.batchComment;
        if (this.batchTag) meta.tags = [this.batchTag];
        if (Object.keys(meta).length > 0) params.meta = JSON.stringify(meta);

        return params;
    }

    /** The bot inputs this field renders, by name. */
    botValues() {
        const data = {};

        for (const name of ["form_ts", "extra_reference_code"]) {
            const input = this.container.querySelector(`.depot-drop-bot [name="${name}"]`);
            if (input) data[name] = input.value;
        }

        return data;
    }

    /**
     * What the form adds to its payload next to this field's own value. The
     * form only reads registered fields, so the bot token (a plain input, not
     * a `.form-field`) would never reach a mailer edit action, and one whose
     * template requires bot protection would silently never send. A nested
     * `.form-field` would not help: nesting makes it a subfield, which the
     * form skips. Only a DEDICATED depot-drop form has this problem; inside a
     * regular form the form carries its own token and honeypot, and ours would
     * overwrite them (an `upload` token on a `create` post stores nothing).
     */
    extraData() {
        if (!this.form?.form?.classList.contains("depot-drop-form")) return {};

        return this.botValues();
    }

    /**
     * The "sent" line, built here so a drop with no comment box renders the
     * same HTML as before. It lives in the comment block when there is one.
     */
    showStatus(text) {
        let line = this.container.querySelector(".depot-drop-status");

        if (!line) {
            line = document.createElement("p");
            line.className = "depot-drop-status";
            line.setAttribute("role", "status");
            const anchor = this.container.querySelector(".depot-drop-comment") || this.container.querySelector(".depot-drop-zone");
            if (anchor?.classList.contains("depot-drop-comment")) {
                anchor.appendChild(line);
            } else if (anchor) {
                anchor.after(line);
            } else {
                this.container.appendChild(line);
            }
        }

        line.textContent = text;
    }

    /**
     * A refused file is reported on its own card. Marking the whole field as
     * errored would also paint the comment box red, and the visitor did
     * nothing wrong there, so with a held queue the field keeps its look.
     */
    error(message) {
        if (!this.holdQueue) {
            super.error(message);
            return;
        }

        this.dispatcher.dispatchEvent("field-error", { field: this, message: message });
        console.warn(`Field Error: ${this.property} - ${message}`);
    }

    /** Server-rendered text first (public pages have no JS catalog), then the catalog. */
    message(key, fallback) {
        return this.settings.messages?.[key] || t(`depot.${key}`, {}, fallback);
    }

    setupDropzone() {
        this.dropzone = new Dropzone(this.container, {
            url               : this.apiUploadUrl(),
            method            : "post",
            // See Droplet::newDropzone() — Dropzone's XHR needs the CSRF header.
            headers           : Object.assign({}, csrfHeaders(), this.form.api.headers),
            parallelUploads   : 3,
            paramName         : this.property,
            autoProcessQueue  : !this.holdQueue,
            thumbnailWidth    : null,
            thumbnailHeight   : null,
            previewsContainer : this.preview,
            previewTemplate   : this.previewTemplate,
            clickable         : Array.from(this.container.getElementsByClassName("dz-clickable")),
            // Hints for the visitor; the server checks every file again.
            acceptedFiles     : this.settings.acceptedFiles || null,
            dictFileTooBig    : this.message("file_too_big", "This file is too big ({{filesize}}MB). The limit is {{maxFilesize}}MB."),
            dictInvalidFileType : this.message("invalid_file_type", "This type of file is not accepted."),
            chunking          : true,
            chunkSize         : 5 * 1024 * 1024,
            maxFilesize       : this.settings.maxFilesize || null,
            maxFiles          : null,
            addedfile         : () => {}, // Disable default, we handle it
            accept            : (file, done) => this.acceptFile(file, done),
        });

        this.dropzone.on("sending", (file, xhr, formData) => {
            for (const [name, value] of Object.entries(this.extraParams())) {
                formData.append(name, value);
            }
        });
        this.dropzone.on("addedfile", file => this.onFileAdded(file));
        this.dropzone.on("processing", file => this.hideRemoveButton(file));
        this.dropzone.on("uploadprogress", (file, progress) => this.onProgress(file, progress));
        this.dropzone.on("success", (file, response) => this.onSuccess(file, response));
        this.dropzone.on("error", (file, message, xhr) => this.onError(file, message, xhr));
        this.dropzone.on("queuecomplete", () => this.onQueueComplete());
        this.dropzone.on("dragenter", () => this.container.classList.add("dz-drag-hover"));
        this.dropzone.on("dragleave", () => this.container.classList.remove("dz-drag-hover"));
        this.dropzone.on("drop", () => this.container.classList.remove("dz-drag-hover"));
    }

    acceptFile(file, done) {
        if (!this.testSet) {
            done();
            return;
        }

        const count = this.preview.querySelectorAll(".depot-drop-card").length;
        if (!this.testSet.processRules(file, count)) {
            const errors = this.testSet.errors.join(" & ");
            this.input.setCustomValidity(errors);
            this.input.reportValidity();
            this.testSet.clearErrors();
            done(errors);
        } else {
            done();
        }
    }

    apiUploadUrl() {
        const api     = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
        const path    = this.settings.path || "";
        const options = path.length > 0 ? { path } : {};
        return this.form.api.buildApiQuery(api, options);
    }

    onFileAdded(file) {
        this.showStatus("");
        // Create preview element from template
        file.previewElement = Dropzone.createElement(this.previewTemplate.trim());
        file.previewElement.classList.add("dz-processing");

        const ext = file.name.split(".").pop().toLowerCase();

        // Set up the card structure
        const iconEl = file.previewElement.querySelector(".file-icon");
        const nameEl = file.previewElement.querySelector(".filename");

        if (iconEl) iconEl.classList.add(`icon-${ext}`);
        if (nameEl) nameEl.textContent = file.name;

        this.showRemoveButton(file);

        // Add to preview container
        this.preview.appendChild(file.previewElement);
    }

    /**
     * Takes a file out of the batch: one still waiting to send, or one refused.
     * It stops the click there because the whole drop area opens the file picker.
     */
    showRemoveButton(file) {
        if (file.previewElement.querySelector(".depot-drop-remove")) return;

        const label  = this.message("remove_file", "Remove");
        const button = document.createElement("button");
        button.type = "button";
        button.className = "depot-drop-remove";
        button.title = label;
        button.setAttribute("aria-label", label);
        button.addEventListener("click", event => {
            event.stopPropagation();
            this.dropzone.removeFile(file);
        });

        file.previewElement.appendChild(button);
    }

    /** A file on its way, or already sent, can no longer be taken back from here. */
    hideRemoveButton(file) {
        file.previewElement?.querySelector(".depot-drop-remove")?.remove();
    }

    onProgress(file, progress) {
        if (!file.previewElement) return;

        let progressBar = file.previewElement.querySelector(".dz-progress");
        if (!progressBar) {
            progressBar = this.createProgressBar();
            file.previewElement.querySelector(".dz-preview").appendChild(progressBar);
        }

        const upload = progressBar.querySelector(".dz-upload");
        const label = progressBar.querySelector(".dz-upload-progress-label");

        if (upload) upload.style.width = progress + "%";
        if (label) label.textContent = progress === 100 ? this.message("processing", "Processing...") : Math.round(progress) + "%";
    }

    createProgressBar() {
        const progress = document.createElement("div");
        progress.className = "dz-progress";
        progress.innerHTML = `
            <span class="dz-upload" data-dz-uploadprogress></span>
            <span class="dz-upload-progress-label" data-dz-uploadprogress>0%</span>
            <div class="dz-status"></div>
        `;
        return progress;
    }

    onSuccess(file, response) {
        if (!file.previewElement) return;

        file.previewElement.classList.remove("dz-processing");
        file.previewElement.classList.add("dz-success", "dz-complete");

        // The check mark lives in the progress bar; a file small enough to
        // upload in one go may never have fired a progress event.
        let progressBar = file.previewElement.querySelector(".dz-progress");
        if (!progressBar) {
            progressBar = this.createProgressBar();
            file.previewElement.querySelector(".dz-preview")?.appendChild(progressBar);
        }
        this.hadSuccess = true;
    }

    onQueueComplete() {
        const succeeded = this.hadSuccess;
        this.hadSuccess = false;

        // Back to holding the queue for the next batch. The comment and tag are
        // cleared only when something went through; after a refusal the visitor
        // keeps what they entered.
        if (this.holdQueue) {
            this.dropzone.options.autoProcessQueue = false;
            if (this.commentText) {
                this.commentText.disabled = false;
                if (succeeded) this.commentText.value = "";
            }
            if (this.tagSelect) {
                this.tagSelect.disabled = false;
                if (succeeded) this.tagSelect.value = "";
            }
            if (this.sendButton) this.sendButton.disabled = false;
            this.batchComment = "";
            this.batchTag = "";
        }

        if (succeeded) this.showStatus(this.message("files_sent", "Your files were sent."));

        if (!succeeded || !this.form) return;

        // Only DEDICATED depot-drop forms (cms.form.depotDrop — no save
        // button, the drop IS the edit) run the form's edit actions when an
        // upload batch completes. Inside a regular form the save flow
        // already runs edit actions (afterSaveAction); firing here too
        // meant drop-files-then-save executed every edit action twice —
        // duplicate mailer-action emails on any form with a dropzone.
        if (!this.form.form.classList.contains("depot-drop-form")) return;
        this.form.runEditActions();
    }

    /**
     * The words to show for a refused upload. The server answers a refusal as
     * JSON: 400 carries a `details` array, 422 and the rest `error.message`.
     * A string reply is shown only when it is a short plain line (Dropzone's
     * own messages, or a plain-text error); an HTML error page from the host
     * (413, 500) never is. A 422 always tells the visitor to reload, since the
     * page is stale, in the words rendered for this page: the server's are
     * always English.
     */
    errorMessage(message, status) {
        if (status === 422) {
            return this.message("upload_expired", "This form has expired. Reload the page and try again.");
        }

        let text = "";

        if (typeof message === "string") {
            text = message.length <= 200 && !/[<>]/.test(message) ? message.trim() : "";
        } else if (message && typeof message === "object") {
            if (Array.isArray(message.details) && message.details.length > 0) {
                text = message.details.map(String).join(" ");
            } else if (typeof message.error === "string") {
                text = message.error;
            } else {
                text = message.error?.message || message.message || "";
            }
        }

        return text || this.message("upload_failed", "The upload did not go through. Please try again.");
    }

    onError(file, message, xhr) {
        if (!file.previewElement) return;

        file.previewElement.classList.remove("dz-processing");
        file.previewElement.classList.add("dz-error");
        this.showRemoveButton(file);

        message = this.errorMessage(message, xhr?.status);

        const status = file.previewElement.querySelector(".dz-status");
        if (status) status.title = message;

        // textContent only: the text is the server's, or came from the visitor.
        let shown = file.previewElement.querySelector(".dz-error-message");
        if (!shown) {
            shown = document.createElement("p");
            shown.className = "dz-error-message";
            shown.setAttribute("role", "alert");
            (file.previewElement.querySelector(".dz-preview") || file.previewElement).appendChild(shown);
        }
        shown.textContent = message;

        console.error("Upload error:", message);
        this.error(message);
    }

    getValue() {
        return [];
    }

    setValue(value) {
        // No-op: write-only
    }

    clearValue() {
        // No-op: write-only
    }

    schema() {
        return { type: "depotDrop", fieldset: this.type };
    }
}
