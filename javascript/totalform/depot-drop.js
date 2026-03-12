import TotalField from "./totalfield";
import DropletTestSet from "./droplet-testset";
import Dropzone from "@deltablot/dropzone";

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

        this.setupDropzone();
    }

    setupDropzone() {
        this.dropzone = new Dropzone(this.container, {
            url               : this.apiUploadUrl(),
            method            : "post",
            headers           : this.form.api.headers,
            parallelUploads   : 3,
            paramName         : this.property,
            autoProcessQueue  : true,
            thumbnailWidth    : null,
            thumbnailHeight   : null,
            previewsContainer : this.preview,
            previewTemplate   : this.previewTemplate,
            clickable         : Array.from(this.container.getElementsByClassName("dz-clickable")),
            acceptedFiles     : null,
            chunking          : true,
            chunkSize         : 5 * 1024 * 1024,
            maxFilesize       : null,
            maxFiles          : null,
            addedfile         : () => {}, // Disable default, we handle it
            accept            : (file, done) => this.acceptFile(file, done),
        });

        this.dropzone.on("addedfile", file => this.onFileAdded(file));
        this.dropzone.on("uploadprogress", (file, progress) => this.onProgress(file, progress));
        this.dropzone.on("success", (file, response) => this.onSuccess(file, response));
        this.dropzone.on("error", (file, message) => this.onError(file, message));
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
        // Create preview element from template
        file.previewElement = Dropzone.createElement(this.previewTemplate.trim());
        file.previewElement.classList.add("dz-processing");

        const ext = file.name.split(".").pop().toLowerCase();

        // Set up the card structure
        const iconEl = file.previewElement.querySelector(".file-icon");
        const nameEl = file.previewElement.querySelector(".filename");

        if (iconEl) iconEl.classList.add(`icon-${ext}`);
        if (nameEl) nameEl.textContent = file.name;

        // Add to preview container
        this.preview.appendChild(file.previewElement);
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
        if (label) label.textContent = progress === 100 ? "Processing..." : Math.round(progress) + "%";
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
        this.hadSuccess = true;
    }

    onQueueComplete() {
        if (!this.hadSuccess || !this.form) return;
        this.hadSuccess = false;
        this.form.runEditActions();
    }

    onError(file, message) {
        if (!file.previewElement) return;

        file.previewElement.classList.remove("dz-processing");
        file.previewElement.classList.add("dz-error");

        if (typeof message === "object") {
            message = message?.error?.message || message?.message || JSON.stringify(message);
        }

        const status = file.previewElement.querySelector(".dz-status");
        if (status) status.title = message;

        if (!message || message === "") {
            message = "Upload failed. Please check server logs for details.";
        }

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
