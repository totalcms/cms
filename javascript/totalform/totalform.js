import TotalCMS from '../totalcms';
import TotalField from './totalfield';
import Identifier from './identifier';
import Checkbox from './checkbox';
import Textarea from './textarea';
import NumberField from './number';
import ColorField from './color';
import DateField from './date';
import SelectField from './select';
import MultiSelectField from './multiselect';
import ListField from './list';
import RangeSlider from './range';
import StyledTextField from './styledtext';
import SVGField from './svg';
import ImageField from './image';

// import ArrayDroplet from './droplet-array';
// import Deck from './deck';
// import MarkdownField from './markdown';
// import Schema from './schema';


//-----------------------------------------------
// Total CMS Form constructor
//-----------------------------------------------
export default class TotalForm {

    // Constructors
    constructor(formRef, options = {}) {
        this.form = this.setForm(formRef);
		formRef.totalform = this;

		if (!formRef || !this.form) {
			console.error("form not found");
			return false;
		}
		this.api             = new TotalCMS();
		this.baseapi         = this.form.dataset.api;
		this.method          = this.form.dataset.method||"PUT";
		this.id              = this.form.dataset.id;
		this.collection      = this.form.dataset.collection;
		this.processingStart = Date.now();
		this.processingLimit = 1500;
		this.states          = ["success","error","processing","clear"];

		this.fields   = this.processFields();
        this.droplets = this.fields.filter(field => field.isDroplet());

		// If an ID is set, we are in edit mode
		if (this.id) {
			this.editMode();
		}

        this.saveListener();
        this.registerButtons();

        window.onbeforeunload = e => {
            if (this.isUnsaved()) {
				e.preventDefault();
                const dialogText = "There are unsaved changes";
                e.returnValue = dialogText;
                return dialogText;
            }
        };
    }

    //-------------------------
    // Utility Methods
    //-------------------------

    // Check to see if the object is a HTML node.
    isDomNode(node){
        return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
    }
    // Set the form via a DOM element or selector string
    setForm(formRef) {
        switch(typeof formRef) {
            case "string":
                return document.getElementById(formRef);
            case "object":
                if (this.isDomNode(formRef)){
                    return formRef;
                }
                break;
        }
        return null;
    }

    //-------------------------
    // Init Form
    //-------------------------
    processFields() {
		const fields = Array.from(this.form.getElementsByClassName("form-field"));
		const fieldObjects = [];
        fields.forEach(field => {
            const object = this.generateFieldObject(field);
            if (object === null) return; // if the object is not set, skip it
            fieldObjects.push(object);

			// Mark as dirty
            field.addEventListener("field-change", e => this.unsaved());
        });
        return fieldObjects;
    }

    registerButton(buttonClass, callback) {
        const buttons = Array.from(this.form.getElementsByClassName(buttonClass));
        buttons.forEach(button => {
            button.addEventListener("click", (event) => {
                event.preventDefault();
                if (typeof callback === "function") callback(button);
                return false;
            });
        });
    }

    registerButtons() {
        this.registerButton("cms-save", () => this.save());
        this.registerButton("cms-delete", () => this.delete());
    }

    generateFieldObject(field) {
        const options = JSON.parse(field.dataset.options||"{}");
        options.form = this;

        switch (field.dataset.type) {
			case "id":
                return new Identifier(field, options);

            case "text":
			case "time":
            case "url":
			case "hidden":
			case "email":
			case "phone":
			case "password":
				return new TotalField(field, options);

			case "textarea":
				return new Textarea(field, options);

            case "checkbox":
            case "toggle":
                return new Checkbox(field, options);

            case "number":
                return new NumberField(field, options);

            case "color":
                return new ColorField(field, options);

            case "date":
			case "datetime":
                return new DateField(field, options);

            case "select":
                return new SelectField(field, options);

            case "multiselect":
                return new MultiSelectField(field, options);

            case "list":
                return new ListField(field, options);

            case "range":
                return new RangeSlider(field, options);

			case "styledtext":
                return new StyledTextField(field, options);

            case "svg":
                return new SVGField(field, options);

			// case "radio":
			// 	return new RadioField(field, options);

            case "image":
				return new ImageField(field,options);

			// case "file":
            // case "gallery":
            // case "depot":
            //     return this.initArrayDroplet(field,options);

			// case "deck":
            //     return new Deck(field, options);

			// case "markdown":
            //     return new MarkdownField(field, options);

            default:
                console.warn("Unknown field",field);
                return null;
        }
    }

    initArrayDroplet(field, options) {
        options.type = field.dataset.type;
        const droplet = new ArrayDroplet(field, options);
        // droplet.updateUri();
        return droplet;
    }

    //-------------------------
    // Submit functions
    //-------------------------
    saveListener() {
		// Prevent the default form submission
        this.form.addEventListener("submit", event => event.preventDefault());

        document.addEventListener("keydown", (event) => {
            if (this.isUnsaved()) {
                if (event.key === "s" && (event.ctrlKey||event.metaKey)) {
                    event.preventDefault();
                    this.save();
                }
            }
        });
    }

	validate() {
		if (this.form.checkValidity()) {
			return true;
		}
		// If the form is invalid, display the custom validation error messages
		this.form.reportValidity();
		return false;
	}

	save() {
		if (!this.validate()) return;
        this.processing();
        this.api.postAPI(this.baseapi, this.generateData(), this.method)
            .then(response => this.afterSave(response))
            .catch(error => this.error(error));
    }

    delete() {
        // Only delete if editing object
        if (!this.isEditMode()) return;

        if (window.confirm("Are you sure that you want to delete this? This cannot be undone.")) {
            this.processing();

            // After delete, redirect to current page without any URL parameters
            this.options.editAction = "redirect";
            this.options.editLink   = location.origin + location.pathname;

            this.api.postAPI(`/collections/${this.collection}/${this.id}`, {}, "DELETE")
                .then(response => this.afterSave(response))
                .catch(error => this.error(error));
        }
    }

    submit() {
        this.save();
    }

    // onSubmit(callback) {
    //     this.form.addEventListener("submit", event => {
    //         event.preventDefault();
    //         if (typeof callback === "function") callback();
    //     });
    // }

    afterSave(response) {
        if (!response) return;

        if (this.droplets.length > 0) {
            // this.saveDroplets(() => this.afterSaveAction(response));
        }
        else {
            this.afterSaveAction(response);
        }
    }

    afterSaveAction(response) {
        this.success();
        const waitUntilSaved = () => {
            // wait until all saving states have completed
            if (!this.saving()) {
				// Mark all fields as saved
				this.fields.forEach(field => field.saved());
                // run actions
                return this.isEditMode() ? this.runEditAction() : this.runNewAction();
            }
            // Check again
            window.setTimeout(waitUntilSaved,100);
        };
        waitUntilSaved();
    }

    runAction(action, url) {
        switch (action) {
            case "refresh":
                location.reload(true);
                break;
            case "redirect-object":
                document.location = url+this.id;
                break;
            case "redirect":
                document.location = url;
                break;
            case "back":
                if (window.history.length > 1) {
                    document.location = document.referrer;
                }
                break;
        }
    }

    runNewAction() {
        // this.runAction(this.options.newAction, this.options.newLink);
    }

    runEditAction() {
        // this.runAction(this.options.editAction, this.options.editLink);
    }

    //-------------------------
    // Form States
    //-------------------------
    isUnsaved() {
        return this.form.classList.contains("unsaved");
    }

    unsaved() {
		this.form.dispatchEvent(new Event("form-change"));
        return this.form.classList.add("unsaved");
    }

    isEditMode() {
        return ("PUT" === this.method.toUpperCase());
    }

    editMode() {
		if (this.isEditMode()) {
			return;
		}

		// Set the method to PUT for editing existing objects
		this.method = "PUT";
		this.form.dataset.method = this.method;

		// The ID cannot be changed in edit mode
		const idField = this.fields.filter(field => field.property === "id").shift();
		idField.disable();
		idField.lock();

		// Update the API to the edit endpoint
		this.baseapi = `${this.baseapi}/${this.id}`;
		this.form.dataset.api = this.baseapi;

		// Update the droplets to autoupload
		this.droplets.forEach(droplet => droplet.autoProcessQueue());
    }

    saving() {
        const current = this.states.filter(state => this.form.classList.contains(state));
        return current.length > 0;
    }

    changeState(newState) {
        const remove = this.states.filter(e => e !== newState); // filer the newState and remove all others

		if (newState) this.form.classList.add(newState);
		this.form.classList.remove(...remove);
		this.form.dispatchEvent(new Event(newState));
    }

    delayProcessing(callback) {
        const processingTime = Date.now() - this.processingStart;
        const delay = this.processingLimit - processingTime;

        window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
    }

    error(error) {
        console.error("Form Error", error);
        this.delayProcessing(() => {
            this.changeState("error");
        });
    }

    clear() {
        // set the state to clear so that it fades out, then remove all classes
        this.changeState("clear");
        window.setTimeout(() => {
            this.changeState();
        }, 1000);
    }

    success() {
		this.editMode();
        this.delayProcessing(() => {
            this.changeState("success");
            this.form.classList.remove("unsaved");
			this.fields.forEach(field => field.saved());
            window.setTimeout(() => {
                this.clear();
            }, 2000);
        });
    }

    processing() {
        this.processingStart = Date.now(); // setup for delayProcessing()
        this.changeState("clear");
        window.setTimeout(() => {
            this.changeState("processing");
        }, 100);
    }

    //-------------------------
    // Droplet Interactions
    //-------------------------

    // The droplet URL requires the ID but that can change
    // This ensures that the URL is updated when it changes
    // updateDropletUri() {
	// 	this.droplets.forEach(droplet => droplet.updateUri());
    // }

    // We only want to process the droplet queue after the inital
    // post request to create the object has been saved
    saveDroplets(callback) {

        let dropletCount = this.droplet.length;

        const dropletComplete = (callback) => {
            // When there are multiple droplets, we need to ensure that the callback
            // is only processed once for the entire form submission.
            // Example: if there are 3 droplets, run after the 3rd time this has been triggered
            dropletCount--;
            if (dropletCount === 0) {
                if (typeof callback === "function") callback();
            }
        };

		this.droplets.forEach(droplet => {
            if (droplet.isComplete()) {
                dropletComplete(callback);
                return;
            }

            droplet.updateUri();
            droplet.onQueueComplete(() => dropletComplete(callback));
            droplet.processQueue();
		});
    }

    //-------------------------
    // Generating Form Data
    //-------------------------

    generateData() {
        const data = {};
		this.fields.forEach(field => {
			if (field.isSubField()) return; // skip subfields
			data[field.property] = field.getValue();
		});
        return data;
    }
}
