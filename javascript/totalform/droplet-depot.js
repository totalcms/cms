import DropletTestSet from "./droplet-testset";
import Dropzone from "@deltablot/dropzone";
import Droplet from "./droplet";
globalThis.Dropzone = Dropzone;

//-----------------------------------------------
// Total CMS Depot Droplet
//-----------------------------------------------
export default class DepotDroplet
{

    constructor(field, options = {}) {
		this.field     = field;
		this.container = this.field.container;

		// Using an embedded template for the preview instead of an extra API request
		this.previewTemplate = this.container.querySelector("template.file-template").innerHTML;

        // Define option defaults
        const defaults = {
			autoProcessQueue  : false,
			previewsContainer : this.container.querySelector(".total-preview"),
			acceptedFiles     : null,                                             // accepts all files
			paramName         : "file",
			apiUrl            : "",
			requestHeaders    : {},
			rules             : {},
			chunking          : true,
        };
		const dataOptions = this.container.dataset.options ? JSON.parse(this.container.dataset.options) : {};
        this.options = Object.assign({}, defaults, options, dataOptions);

        // Get the rule set for uploading the file
        if (this.options.rules && Object.keys(this.options.rules).length > 0) {
            this.testSet = new DropletTestSet(this.options.rules);
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
			url               : this.options.apiUrl,
			method            : "post",
			headers           : this.options.requestHeaders,
			parallelUploads   : 3,
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
			chunking          : this.options.chunking,
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
		this.dropzone.on("uploadprogress", (file, progress, bytes) => this.event_uploadprogress(file, progress, bytes));
		this.dropzone.on("error", (file, message) => this.event_error(file, message));
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
        // Add accepts and reject methods to the file object to validate with the test sets
        file.acceptFile = done;
        file.rejectFile = function(msg){ done(msg); };
        this.processTestSet(file);
    }

    processTestSet(file) {
        // Process file rules
        if (this.testSet) {
			const count = this.container.querySelectorAll(".file").length;
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

    // When a file is added to the list
    event_addedfile(file) {
        file.previewElement  = Dropzone.createElement(this.previewTemplate.trim());
        file.previewTemplate = file.previewElement;

        file.previewTemplate.classList.add("dz-processing");

        let uploadFolder = this.container.querySelector(".depot-browser");

        const selectedFolder = this.container.querySelector(".folder.selected");
        if (selectedFolder) {
            uploadFolder = selectedFolder.parentNode.querySelector(".folder-contents");
        }

        const name = file.previewElement.querySelector(".file");
        const size = file.previewElement.querySelector(".size");
        const ext  = file.name.split(".").pop().toLowerCase();

        name.textContent = file.name;
        size.textContent = this.field.bytesToString(file.size);
        name.className   = `file file-icon icon-${ext}`;

        // Add to the top of the file list. If there are folders, skip them
        let firstFile = null;
        for (let i = 0; i < uploadFolder.children.length; i++) {
            if (!this.is_folder(uploadFolder.children[i])) {
                firstFile = uploadFolder.children[i];
                break;
            }
        }
        if (firstFile) {
            uploadFolder.insertBefore(file.previewElement, firstFile);
        } else {
            uploadFolder.appendChild(file.previewElement);
        }

		// Process fields first to initialize any new form fields in the preview
		this.field.fileAdded(file);

        if (!this.dropzone.options.autoProcessQueue) {
            // if autoprocessQueue is not used, mark as unsaved
			this.field.changed();
        }
    }

    is_folder(item) {
        return item.firstChild.tagName === "DETAILS";
    }

    // Gets called periodically whenever the file upload progress changes
    event_uploadprogress(file, progress, bytes) {
        const results = [];

        if (file.previewElement) {

            let nodes = file.previewElement.querySelectorAll("[data-dz-uploadprogress]");
            if (nodes.length == 0) {
                const progress = this.createProgressBar();
                file.previewElement.appendChild(progress);
                nodes = file.previewElement.querySelectorAll("[data-dz-uploadprogress]");
            }

            for (const node of nodes) {
                if (node.nodeName === "PROGRESS") {
                    results.push(node.value = progress);
                }
                else if (node.classList.contains("dz-upload-progress-label")) {
                    if (progress == 100) {
                        results.push(node.innerHTML = "Uploading...");
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
    // When an error has occurred
    event_error(file, message) {
        if (typeof(message) === "object") message = message?.error?.message || message?.message || JSON.stringify(message);
        file.previewElement.classList.remove("saving");
        file.previewElement.classList.add("error","dz-error");
		if (this.testSet && this.testSet.errors) {
			console.warn("Pre-Upload Validation Errors:",this.testSet.errors);
			return;
		}
		if (!message) {
			console.error("Undefined Droplet Error");
		}
		const status = file.previewElement.querySelector(".dz-status");
		if (status) status.title = message;
		this.field.error(message);
    }

    // The file has been uploaded successfully
    event_success(file, response) {
        if (typeof(response) === "object") {
            if (file.previewElement) {
                file.previewElement.classList.remove("dz-processing");
                file.previewElement.classList.add("dz-success");
				file.previewElement.addEventListener("click", () => {
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
        this.field.updateAPIUrl();
        this.container.classList.add("dz-drag-hover");
    }

    // The user dragged a file out of the Dropzone
    event_dragleave(event) {
        this.container.classList.remove("dz-drag-hover");
    }

    // The user dropped something onto the dropzone
    event_drop(event) {
        this.container.classList.remove("dz-drag-hover");
    }
}
