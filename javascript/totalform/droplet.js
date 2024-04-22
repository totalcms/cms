import DropletTestSet from "./droplet-testset";
import Dropzone from "@deltablot/dropzone";
globalThis.Dropzone = Dropzone;

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class Droplet {

    constructor(field, options = {}) {
		this.field     = field;
		this.container = this.field.container;

		// Using an embedded template for the preview instead of an extra API request
		this.previewTemplate = this.container.querySelector("template").innerHTML;

        // Define option defaults
        const defaults = {
            autoProcessQueue  : false,
            previewsContainer : this.container.querySelector(".total-preview"),
            acceptedFiles     : "image/*",
            paramName         : "file",
            requestHeaders    : {},
            gallery           : false,
        };
        this.options = Object.assign({}, defaults, options);

        this.options.gallery = (this.options.type === "gallery"||this.options.type === "depot");

        // Get the rule set for uploading the file
        if (this.container.dataset.rules) {
            const rules = JSON.parse(this.container.dataset.rules);
            this.testSet = new DropletTestSet(rules);
        }

        this.setupDropzone();
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
            url               : this.options.apiUrl,
            method            : "post",
            headers           : this.options.requestHeaders,
            parallelUploads   : 1,
            paramName         : this.options.paramName,
            autoProcessQueue  : this.options.autoProcessQueue,
            thumbnailWidth    : null,
            thumbnailHeight   : null,
            previewsContainer : this.options.previewsContainer,
            previewTemplate   : this.previewTemplate,
            clickable         : Array.from(this.container.getElementsByClassName("dz-clickable")),
            forceFallback     : false,
            addedfile         : disableFunction,
            acceptedFiles     : this.options.acceptedFiles,
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
    }

    onQueueComplete(callback) {
        this.dropzone.on("success", () => {
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
        // Create functions that get checked after the thumbnail method has access to the file data
        // The dimensions and size of the image will not be known until the event_thumbnail() loads.
        // This delays that execution until then.
        file.acceptFile = done;
        file.rejectFile = function(msg){ done(msg); };
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

        if (!this.options.gallery) {
            this.dropzone.previewsContainer.innerHTML = "";
        }
        this.dropzone.previewsContainer.appendChild(file.previewElement);

        if (!this.dropzone.options.autoProcessQueue) {
            // if autoprocessQueue is not used, mark as unsaved
			this.field.changed();
        }
    }

    // When the thumbnail has been generated. Receives the dataUrl as second parameter.
    event_thumbnail(file,data) {
        file.previewElement.classList.remove("dz-file-preview");
        const thumbs = file.previewElement.querySelectorAll("[data-dz-thumbnail]");

        for (const thumb of thumbs) {
            thumb.alt = file.name;
            thumb.src = data;
        }

        // Process file rules
        // This happens here becuase its the first time that we have access to file info
        if (this.testSet) {
            this.testSet.processRules(file);
            if (!this.testSet.pass) {
                console.error(this.testSet.errors);
                file.rejectFile(this.testSet.errors);
            }
        }
        file.acceptFile();

        return setTimeout(((function() {
            return function() {
                return file.previewElement.classList.add("dz-image-preview");
            };
        })(this)),1);
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
        if (typeof(message) === "object") message = message.message;
        file.previewElement.classList.remove("saving");
        file.previewElement.classList.add("error","dz-error");
        this.field.error(message);
    }

    // The file has been uploaded successfully
    event_success(file, response) {
        if (typeof(response) === "object") {
            if (file.previewElement) {
                if (typeof(response.data) === "string") {
                    file.previewElement.dataset.filename = this.basename(response.data);
                }
                file.previewElement.classList.remove("dz-processing");
                file.previewElement.classList.add("dz-success");
            }
        }
        else {
            // this.event_error(file, this.options.localizeStrings.unknownError+" : "+response);
            this.event_error(file, "Unknown error: "+response);
        }
    }

    //-----------------------------------------------------------------------
    // Mouse Event Methods
    //-----------------------------------------------------------------------

    // The user dragged a file onto the Dropzone
    event_dragenter(event) {
        const classesToRemove = ["dz-processing", "dz-success", "dz-complete"];
        const preview = this.container.getElementsByClassName("dz-preview").item(0);
        if (preview) {
            preview.classList.remove(...classesToRemove);
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
