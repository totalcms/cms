import TotalField from "./totalfield";
// import Dropzone from "dropzone";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class Droplet extends TotalField {

    constructor(container, options) {
        super(container, options);

		// TODO: This should probably swapped out for getting the HTML from the API request
		this.previewTemplate = this.container.querySelector("template").innerHTML;

        // Define option defaults
        const defaults = {
            autoProcessQueue  : false,
            previewsContainer : this.container.getElementsByClassName("total-preview").item(0),
            previewTemplate   : "",
            acceptedFiles     : "image/*",
            paramName         : this.name,
            requestHeaders    : {},
            type              : "file",
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

    getValue() {
        return this.input.value.length > 0 ? JSON.parse(this.input.value) : {};
    }

    setValue(image) {
        if (image === null || !image.filename) {
            console.warn("Image object not valid",image);
            return;
        }

        // Set the value to the image object
        this.input.value = JSON.stringify(image);

        // Create Image Works API for the preview image
        const imageWorks = new ImageWorks({
            collection : this.form.collection,
            id         : this.form.id,
            property   : this.name,
            file       : image.filename,
            date       : image.uploadDate
        });
        // Get all of the data needed to build the imageWorks query
        const rules      = JSON.parse(this.container.dataset.imageworks);
        const imageQuery = imageWorks.buildQuery(rules);
        const preview    = this.container.querySelectorAll(".total-preview").item(0);

        this.api.fetchCachedAPI("/templates/admin/image").then(json => {
            this.api.processTemplate({"image":imageQuery}, json.template, preview);
        });

    }

    apiUrl() {
        const components = [this.options.uri, "collections", this.form.collection, this.form.id, this.name];
        return components.join("/");
    }

    autoProcessQueue() {
        if (this.dropzone) {
            this.dropzone.options.autoProcessQueue = true;
        }
        else {
            console.warn("Unable to enable autoProcessQueue");
        }
    }

    updateUri() {
        if (this.dropzone) {
            this.dropzone.options.url = this.apiUrl();
        }
        else {
            console.warn("Unable to update dropzone URI");
        }

    }

    newDropzone() {
        const disableFunction = function(){};

		const api = `/collections/${this.form.collection}/${this.form.id}/${this.name}`;

        return new Dropzone(this.container, {
            url               : this.api.apiUrl(api),
            method            : "post",
            headers           : this.options.requestHeaders,
            parallelUploads   : 1,
            paramName         : this.options.paramName,
            autoProcessQueue  : this.form.form.classList.contains("edit-form"),
            thumbnailWidth    : null,
            thumbnailHeight   : null,
            previewsContainer : this.options.previewsContainer,
            previewTemplate   : this.previewTemplate,
            clickable         : [
                this.container.getElementsByClassName("dz-clickable").item(0),
                // this.container.getElementsByTagName("img").item(0)
            ],
            forceFallback : false,
            addedfile     : disableFunction,
            acceptedFiles : this.options.acceptedFiles,
            accept        : this.accept
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
        formData.append("filesize", file.size);
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
            this.container.classList.add("unsaved");
            this.form.unsaved();
        }
    }

    // When the thumbnail has been generated. Receives the dataUrl as second parameter.
    event_thumbnail(file,data) {
        file.previewElement.classList.remove("dz-file-preview");
        const thumbs = file.previewElement.querySelectorAll("[data-dz-thumbnail]");

        for (const thumb of thumbs) {
            thumb.alt = file.name;
            thumb.src = data;
            // thumb.style.width="auto";
            // thumb.style.height="auto";
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
        this.form.error(message);
        // Add error to tooltip.js (replace foundation tooltips below)
        // $(file.previewElement).find('.has-tip').attr('title',message);
        // $(document).foundation('tooltip','reflow');
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

	schema() {
        return {
            type     : "object",
            fieldset : this.options.type
        };
    }
}
