import DropletTestSet from "./droplet-testset";
import Dropzone from "@deltablot/dropzone";
import FileField from "./file";
globalThis.Dropzone = Dropzone;

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class Droplet {

    constructor(field, settings = {}) {
		this.field     = field;
		this.container = this.field.container;

		// Using an embedded template for the preview instead of an extra API request
		this.previewTemplate = this.container.querySelector("template").innerHTML;

        // Define option defaults
        const defaults = {
			autoProcessQueue  : false,
			previewsContainer : this.container.querySelector(".total-preview"),
			acceptedFiles     : null,                                             // accepts all files
			paramName         : "file",
			apiUrl            : "",
			requestHeaders    : {},
			rules             : {},
			singleMode        : true,
			chunking          : false,
        };
		const dataSettings = this.container.dataset.settings ? JSON.parse(this.container.dataset.settings) : {};
        this.settings = Object.assign({}, defaults, settings, dataSettings);

        // Get the rule set for uploading the file
        if (this.settings.rules && Object.keys(this.settings.rules).length > 0) {
            this.testSet = new DropletTestSet(this.settings.rules);
        }

        this.setupDropzone();
    }

	updateUrl(url) {
		this.dropzone.options.url = url;
	}

    autoProcessQueue() {
        if (!this.dropzone) {
			console.warn("Unable to enable autoProcessQueue");
			return;
		}
		this.dropzone.options.autoProcessQueue = true;
    }

    newDropzone() {
        const disableFunction = function(){};

        return new Dropzone(this.container, {
			url               : this.settings.apiUrl,
			method            : "post",
			headers           : this.settings.requestHeaders,
			parallelUploads   : 3,
			paramName         : this.settings.paramName,
			autoProcessQueue  : this.settings.autoProcessQueue,
			thumbnailWidth    : null,
			thumbnailHeight   : null,
			previewsContainer : this.settings.previewsContainer,
			previewTemplate   : this.previewTemplate,
			clickable         : Array.from(this.container.getElementsByClassName("dz-clickable")),
			forceFallback     : false,
			addedfile         : disableFunction,
			acceptedFiles     : this.settings.acceptedFiles,
			chunking          : this.settings.chunking,
            chunkSize         : 5 * 1024 * 1024, // 5MB
			accept            : (file, done) => this.accept(file, done),
            maxFilesize       : null, // disabled in favor of test sets
            maxFiles          : null, // disabled in favor of test sets
        });
    }

    setupDropzone() {
		// Create new Dropzone
		this.dropzone = this.newDropzone();

		// File Events
		this.dropzone.on("addedfile", file => this.event_addedfile(file));
		this.dropzone.on("thumbnail", (file,data) => this.event_thumbnail(file,data));
		this.dropzone.on("uploadprogress", (file, progress, bytes) => this.event_uploadprogress(file, progress, bytes));
		this.dropzone.on("error", (file, message) => this.event_error(file, message));
		this.dropzone.on("sending", (file, xhr, formData) => this.event_sending(file, xhr, formData));
		this.dropzone.on("success", (file, xhr, formData) => this.event_success(file, xhr, formData));

		// Mouse Events
		this.dropzone.on("dragenter", event => this.event_dragenter(event));
		this.dropzone.on("dragleave", event => this.event_dragleave(event));
		this.dropzone.on("drop", event => this.event_drop(event));

		// Event Listeners
		this.container.addEventListener("processing", () => {
			this.dropzone.options.autoProcessQueue = true;
		});

		// Refresh the upload URL right before each file is sent. The droplet's
		// URL is captured at field construction; for fields nested in a card or
		// a freshly-added deck item, the parent id or deck-item id may not have
		// existed yet — without this refresh, autoupload posts to the stale URL.
		this.dropzone.on("processing", () => {
			if (typeof this.field.updateAPIUrl === "function") {
				this.field.updateAPIUrl();
			}
		});
    }

    onQueueComplete(callback) {
        this.dropzone.on("queuecomplete", () => {
            if (typeof callback === "function") callback();
        });
    }

    pendingFiles() {
        const files = this.dropzone.getQueuedFiles().concat(this.dropzone.getUploadingFiles());
        return files;
    }

    isComplete() {
        return (this.pendingFiles().length === 0);
    }

    processQueue() {
        this.dropzone.processQueue();
    }

    accept(file,done) {
        // Add accepts and reject methods to the file object to validate with the test sets
        file.acceptFile = done;
        file.rejectFile = function(msg){ done(msg); };

        // HEIC files may not trigger thumbnail event, so process tests here
        if (this.isHeicFile(file)) {
            this.processTestSet(file);
            file.testSetProcessed = true; // Mark as processed to avoid double-processing
        } else if (!file.type.startsWith("image")) {
            // If the file is not an image, process the tests
            // Other images will get processed after the thumbnail is generated in the event_thumbnail method
            this.processTestSet(file);
            file.testSetProcessed = true; // Mark as processed to avoid double-processing
        }
    }

    processTestSet(file) {
        // Process file rules
        if (this.testSet) {
			const count = this.container.querySelectorAll(".dz-preview").length;
            if (!this.testSet.processRules(file, count)) {
                file.rejectFile(this.testSet.errors);
				this.displayTestSetErrors();
            } else {
                file.acceptFile();
            }
        } else {
            file.acceptFile();
        }
    }

    //-----------------------------------------------------------------------
    // File Event Methods
    //-----------------------------------------------------------------------

    // Called just before the file is sent
    event_sending(file,xhr,formData) {
        // Add additional form data
        // formData.append("filesize", file.size);
        // formData.append("alt", file.size);
        // formData.append("link", file.size);
        // formData.append("colors", file.size);
    }

    // When a file is added to the list
    event_addedfile(file) {
        file.previewElement  = Dropzone.createElement(this.dropzone.options.previewTemplate.trim());
        file.previewTemplate = file.previewElement;

        if (this.settings.singleMode) {
			// Remove preview for image
			Array.from(this.dropzone.previewsContainer.children).forEach(node => node.remove());
        }
        this.dropzone.previewsContainer.appendChild(file.previewElement);

        // For HEIC files, show a placeholder since browser can't generate thumbnail
        if (this.isHeicFile(file)) {
            this.showHeicPlaceholder(file);
        }

        if (!this.dropzone.options.autoProcessQueue) {
            // if autoprocessQueue is not used, mark as unsaved
			this.field.changed();
        }
		this.field.fileAdded(file);
    }

    // When the thumbnail has been generated. Receives the dataUrl as second parameter.
    event_thumbnail(file,data) {
        // For HEIC files, we already set a placeholder, so skip thumbnail processing
        if (this.isHeicFile(file)) {
            return;
        }

        // Guard against removed preview element (user navigated away during thumbnail generation)
        if (!file.previewElement) {
            return;
        }

        file.previewElement.classList.remove("dz-file-preview");

        // Only process test set if not already processed (e.g., non-image files and HEIC already processed)
        if (!file.testSetProcessed) {
            this.processTestSet(file);
        }

        const thumbs = file.previewElement.querySelectorAll("[data-dz-thumbnail]");
        for (const thumb of thumbs) {
            thumb.alt = file.name;
            thumb.src = data;
        }
    }

    // Check if file is HEIC/HEIF format
    isHeicFile(file) {
        if (!file || !file.name) return false;
        const ext = file.name.split('.').pop().toLowerCase();
        return ext === 'heic' || ext === 'heif';
    }

    // Show placeholder for HEIC files since browser can't generate thumbnail
    showHeicPlaceholder(file) {
        if (!file.previewElement) return;

        // Find the thumbnail image element
        const thumbs = file.previewElement.querySelectorAll("[data-dz-thumbnail]");
        for (const thumb of thumbs) {
            // Remove data-dz-thumbnail attribute to prevent Dropzone from overwriting our placeholder
            thumb.removeAttribute("data-dz-thumbnail");

            // Create SVG placeholder with text
            const svg = `
                <svg xmlns="http://www.w3.org/2000/svg" width="600" height="400" viewBox="0 0 600 400">
                    <rect width="600" height="400" fill="#f0f0f0"/>
                    <text x="50%" y="40%" font-family="system-ui, -apple-system, sans-serif" font-size="18" fill="#666" text-anchor="middle" dominant-baseline="middle">
                        HEIC Image
                    </text>
                    <text x="50%" y="50%" font-family="system-ui, -apple-system, sans-serif" font-size="14" fill="#999" text-anchor="middle" dominant-baseline="middle">
                        Converting to JPEG...
                    </text>
                    <text x="50%" y="60%" font-family="system-ui, -apple-system, sans-serif" font-size="12" fill="#aaa" text-anchor="middle" dominant-baseline="middle">
                        Reload page after upload to view image
                    </text>
                </svg>
            `;
            // Convert SVG to data URL
            const dataUrl = 'data:image/svg+xml;base64,' + btoa(svg);
            thumb.src = dataUrl;
            thumb.alt = file.name;
        }

        // Remove the file-preview class since we have a visual placeholder
        file.previewElement.classList.remove("dz-file-preview");
    }

    // Gets called periodically whenever the file upload progress changes
    event_uploadprogress(file, progress, bytes) {
        const results = [];

        if (file.previewElement) {
            const nodes = file.previewElement.querySelectorAll("[data-dz-uploadprogress]");
            for (const node of nodes) {
                if (node.nodeName === "PROGRESS") {
                    results.push(node.value = progress);
                }
                else if (node.classList.contains("dz-upload-progress-label")) {
                    if (progress == 100) {
                        results.push(node.innerHTML = "Processing...");
                    }
                    else {
                        results.push(node.innerHTML = Math.round(progress) + "%");
                    }
                }
                else {
                    results.push(node.style.width = progress + "%");
                }
            }
        }
        return results;
    }

    // When an error has occurred
    event_error(file, message) {
        if (typeof(message) === "object") message = message?.error?.message || message?.message || JSON.stringify(message);
        if (file.previewElement) {
            file.previewElement.classList.remove("saving");
            file.previewElement.classList.add("error","dz-error");
            const status = file.previewElement.querySelector(".dz-status");
            if (status) status.title = message;
        }
		if (this.testSet && this.testSet.errors) {
			console.warn("Pre-Upload Validation Errors:",this.testSet.errors);
			return;
		}
		if (!message || message === '') {
			console.error("Upload error with undefined message. File:", file.name, "Status:", file.status, "XHR:", file.xhr);
			message = "Upload failed. Please check server logs for details.";
		}
		console.error("Droplet upload error:", message);
		this.field.error(message);
    }

    // The file has been uploaded successfully
    event_success(file, response) {
        if (typeof(response) === "object") {
            if (file.previewElement) {
                file.previewElement.classList.remove("dz-processing");
                file.previewElement.classList.add("dz-success");
				file.previewElement.addEventListener("pointerover", () => {
					// remove the success class after the user hovers over the image
					// this allows the actionbar to be interacted with
					file.previewElement.classList.remove("dz-success","dz-complete");
				}, {once:true});
				this.field.fileUploaded(file, response);
            }
        }
        else {
            // this.event_error(file, this.options.localizeStrings.unknownError+" : "+response);
            this.event_error(file, "Unknown error: "+response);
        }
    }

	displayTestSetErrors() {
        if (this.testSet.errors.length > 0) {
            this.field.input.setCustomValidity(this.testSet.errors.join(" & "));
            this.field.input.reportValidity();
            this.testSet.clearErrors();
        }
	}

    //-----------------------------------------------------------------------
    // Mouse Event Methods
    //-----------------------------------------------------------------------

    // The user dragged a file onto the Dropzone
    event_dragenter(event) {
        const classesToRemove = ["dz-success", "dz-complete"];
        const previews = Array.from(this.dropzone.previewsContainer.children);
        if (previews.length > 0) {
			previews.forEach(preview => preview.classList.remove(...classesToRemove));
        }
        return this.container.classList.add("dz-drag-hover");
    }

    // The user dragged a file out of the Dropzone
    event_dragleave(event) {
        return this.container.classList.remove("dz-drag-hover");
    }

    // The user dropped something onto the dropzone
    event_drop(event) {
        return this.container.classList.remove("dz-drag-hover");
    }
}
