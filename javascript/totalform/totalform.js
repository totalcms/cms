import TotalField from 'totalfield';

//-----------------------------------------------
// Total CMS Form constructor
//-----------------------------------------------
export default class TotalForm {

    // Constructors
    constructor(formRef, options = {}) {
        if (!formRef){ return false; }

        this.form = this.setForm(formRef);

        // Define option defaults
        const defaults = {
            newAction  : "none",
            newLink    : "none",
            editAction : window.location.href,
            editLink   : window.location.href
        };
        // merge those with defaults and arguments passed
        const local = this.form.dataset.options ? JSON.parse(this.form.dataset.options) : {};
        this.options = Object.assign({}, defaults, options, local);

        this.api = new TotalCMS();

        this.collection      = this.find("input[name=collection]").value;
        this.baseapi         = `/collections/${this.collection}`;
        this.id              = this.api.getUrlParameter("id")||this.api.getUrlParameter("permalink");
        this.indicator       = null;
        this.processingStart = Date.now();
        this.processingLimit = 1500;
        this.states          = ["success","error","processing","clear"];

        this.fieldsets    = this.findAll("fieldset").filter(field => !this.insideDeck(field));
        this.droplets     = this.fieldsets.filter(field => field.classList.contains("droplet"));
        this.fieldObjects = this.processFieldsets();

        this.schema = new Schema(this);

        this.addTemplates();
        this.saveListener();
        this.registerButtons();

        // Get the data from the server
        if (this.id) this.getServerObject();

        // window.onbeforeunload = (e) => {
        //     if (this.isUnsaved()) {
        //         const dialogText = "There are unsaved changes";
        //         e.returnValue = dialogText;
        //         return dialogText;
        //     }
        //     e.preventDefault();
        //     return false;
        // };
    }

    //-------------------------
    // Utility Methods
    //-------------------------

    // Find the first instance of a selector within the form
    find(selector) {
        return this.findAll(selector).shift();
    }
    // Find the all instance of a selector within the form
    findAll(selector) {
        return Array.from(this.form.querySelectorAll(selector));
    }
    // Filter for determining if inside of a Deck field
    insideDeck(node) {
        return node.parentNode.closest("fieldset.deck-box") ? true : false;
    }
    // Check to see if the object is a HTML node.
    isDomNode(node){
        return typeof node === "object" && "nodeType" in node && node.nodeType === 1;
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
    processFieldsets() {
        const data = {};
        this.fieldsets.forEach(field => {
            const object = this.generateFieldObject(field);
            if (object === null) return; // if the object is not set, skip it
            data[field.dataset.name] = object;

            field.addEventListener("change", event => {
                // Marke as dirty
                this.unsaved();
            });
        });
        return data;
    }

    registerButton(buttonClass, callback) {
        const allButtons = Array.from(document.getElementsByClassName(buttonClass));
        const buttons = allButtons.filter(button => {
            // If the button is inside of a form, only accept the button inside this form
            const form = button.closest("form");
            if (form) return form === this.form;
            // Accept all buttons not in a form
            return true;
        });
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
            case "text":
            case "video":
                return new Fieldset(field, options);

            case "styledtext":
                return new StyledTextField(field, options);

            case "markdown":
                return new MarkdownField(field, options);

            case "svg":
                return new SVGField(field, options);

            case "select":
                return new SelectField(field, options);

            case "multiselect":
                return new MultiSelectField(field, options);

            case "number":
                return new NumberField(field, options);

            case "checkbox":
            case "toggle":
                return new Checkbox(field, options);

            case "permalink":
                return this.initPermalink(field, options);

            case "range":
                return new RangeSlider(field, options);

            case "color":
                return new ColorPicker(field, options);

            case "date":
                return new DatePicker(field, options);

            case "deck":
                return new Deck(field, options);

            case "image":
            case "file":
                return this.initDroplet(field,options);

            case "gallery":
            case "depot":
                return this.initArrayDroplet(field,options);

            case "list":
                return new ListComplete(field, options);

            default:
                console.warn("Unknown fieldset",fieldset);
                return null;
        }
    }

    initPermalink(field, options) {
        this.permalink = new Permalink(field, options);
        field.addEventListener("change", event => this.updatePermalink());
        return this.permalink;
    }

    initArrayDroplet(field, options) {
        options.type = field.dataset.type;
        const droplet = new ArrayDroplet(field, options);
        droplet.updateUri();
        return droplet;
    }

    initDroplet(field, options) {
        options.type = field.dataset.type;
        const droplet = new Droplet(field, options);
        droplet.updateUri();
        return droplet;
    }

    //-------------------------
    // Populate Form functions
    //-------------------------
    getServerObject() {
        // AJAX call to get the object and populate form
        // We do not want the cached fetchAPI function since we need to ensure this gets the live data
        this.api.fetchAPI(`${this.baseapi}/${this.id}`).then(object => this.populateForm(object));
    }

    populateForm(object) {

        this.id = object.id;

        for (const property in object) {
            // fetch the object for this field
            const field = this.fieldObjects[property];

            if (!field) {
                console.warn(`Unable to find form field for object property: ${property}`);
                continue;
            }

            // Set the value for the field
            field.setValue(object[property]);
        }

        // Add the edit-form class to the form since its will be editing an existing element
        // This is a utility class used to add differnt styling and features to forms
        this.editMode();

        // Update all droplets with the new id
        // this.updateDropletUri();
    }

    //-------------------------
    // Submit functions
    //-------------------------
    saveListener() {
        this.form.addEventListener("submit", event => {
            event.preventDefault();
            this.save();
        });
        document.addEventListener("keydown", (event) => {
            if (this.isUnsaved()) {
                if (event.key === "s" && (event.ctrlKey||event.metaKey)) {
                    event.preventDefault();
                    this.save();
                }
            }
        });
    }

    save() {
        this.updatePermalink();
        this.processing();
        this.api.postAPI(this.baseapi, this.generateData())
            .then(response => this.afterSave(response))
            .catch(error => this.error(error));
    }

    delete() {
        // Only delete if editing object
        if (!this.isEditMode()) return;

        if (window.confirm("Are you sure that you want to delete this? This cannot be undone.")) {
            this.updatePermalink();
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

    updatePermalink() {
        this.id = this.permalink.id;
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
            this.saveDroplets(() => this.afterSaveAction(response));
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
        this.runAction(this.options.newAction, this.options.newLink);
    }

    runEditAction() {
        this.runAction(this.options.editAction, this.options.editLink);
    }

    //-------------------------
    // Form Templates
    //-------------------------
    addTemplates() {
        this.templateSaveIndicator();
    }

    templateSaveIndicator() {
        const indicator = document.getElementById("form-save-indicator");
        if (indicator) {
            this.indicator = indicator;
        }
        else {
            this.api.fetchCachedAPI("/templates/admin/form-save").then(json => {
                const body = document.getElementsByTagName("body")[0];
                this.api.processTemplate({}, json.template, body);
                this.indicator = document.getElementById("form-save-indicator");
                this.indicator.addEventListener("click", () => this.indicator.classList = "");
            });
        }
    }

    //-------------------------
    // Form States
    //-------------------------
    isUnsaved() {
        return this.form.classList.contains("unsaved");
    }

    unsaved() {
        return this.form.classList.add("unsaved");
    }

    isEditMode() {
        return this.form.classList.contains("edit-form");
    }

    editMode() {
        this.form.classList.add("edit-form");

        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];
            // Set all Droplets to autoprocessqueue
            if (field.dropzone) field.autoProcessQueue();
        }
    }

    saving() {
        const current = this.states.filter(state => this.form.classList.contains(state));
        return current.length > 0;
    }

    changeState(newState) {
        const remove = this.states.filter(e => e !== newState); // filer the newState and remove all others

        const elements = [this.indicator, this.form];
        for (const element of elements) {
            if (newState) element.classList.add(newState);
            element.classList.remove(...remove);
        }
    }

    delayProcessing(callback) {
        const processingTime = Date.now() - this.processingStart;
        const delay = this.processingLimit - processingTime;

        window.setTimeout(() => {
            if (typeof callback === "function") callback();
        }, delay);
    }

    error(error) {
        console.error("Form Error: "+error);
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
        this.delayProcessing(() => {
            this.changeState("success");
            this.form.classList.remove("unsaved");
            for(const field of this.fieldsets) {
                field.classList.remove("unsaved");
            }
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
    updateDropletUri() {
        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];
            if (field.dropzone) field.updateUri();
        }
    }

    // We only want to process the droplet queue after the inital
    // post request to create the object has been saved
    saveDroplets(callback) {

        let dropletCount = 0;
        for (const name in this.fieldObjects) {
            if (this.fieldObjects[name].dropzone) dropletCount++;
        }

        const dropletComplete = (callback) => {
            // When there are multiple droplets, we need to ensure that the callback
            // is only processed once for the entire form submission.
            // Example: if there are 3 droplets, run after the 3rd time this has been triggered
            dropletCount--;
            if (dropletCount === 0) {
                if (typeof callback === "function") callback();
            }
        };

        for (const name in this.fieldObjects) {
            const field = this.fieldObjects[name];

            // Only Droplets
            if (!field.dropzone) continue;

            if (field.isComplete()) {
                dropletComplete(callback);
                continue;
            }

            field.updateUri();
            field.onQueueComplete(() => dropletComplete(callback));
            field.processQueue();
        }
    }

    //-------------------------
    // Generating Form Data
    //-------------------------

    generateData() {
        const data = {};
        for(const name in this.fieldObjects) {
            const value = this.fieldObjects[name].getValue();
            // ingore null objects
            if (value !== null) data[name] = value;
        }
        return data;
    }
}
