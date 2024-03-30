import {
  TotalCMS,
  __name
} from "./chunk-ZKWNB7ZA.js";

// javascript/totalform/totalfield.js
var TotalField = class {
  constructor(container, options) {
    this.container = container;
    this.input = this.container.querySelector("input,textarea,select");
    container.totalfield = this;
    this.type = container.dataset.type;
    this.name = this.input.name;
    const defaults = {
      form: null
    };
    this.options = Object.assign({}, defaults, options);
    this.form = this.options.form;
    delete this.options.form;
    if (this.form) {
      this.api = this.form.api;
    }
    this.changeListener();
  }
  changeListener() {
    this.input.addEventListener("change", () => this.changed(), { once: true });
    this.input.addEventListener("input", () => this.changed(), { once: true });
  }
  isDroplet() {
    const droplets = ["image", "file", "gallery", "depot"];
    return droplets.includes(this.type);
  }
  isFroala() {
    const froalaTypes = ["styledtext", "svg"];
    return froalaTypes.includes(this.type);
  }
  getValue() {
    return this.input.value;
  }
  setValue(value) {
    this.input.value = value;
    if (this.isFroala()) {
      this.input.froalaEditor("html.set", value);
    }
    this.changed();
  }
  changed() {
    this.container.classList.add("unsaved");
    this.container.dispatchEvent(new Event("change"));
  }
  saved() {
    this.container.classList.remove("unsaved");
    this.changeListener();
  }
  schema() {
    return {
      "type": this.type,
      "field": "text"
    };
  }
};
__name(TotalField, "TotalField");

// javascript/totalform/totalform.js
var TotalForm = class {
  // Constructors
  constructor(formRef, options = {}) {
    this.form = this.setForm(formRef);
    formRef.totalform = this;
    if (!formRef || !this.form) {
      console.error("form not found");
      return false;
    }
    this.api = new TotalCMS();
    this.baseapi = this.form.dataset.api;
    this.method = this.form.dataset.method || "PUT";
    this.id = this.form.dataset.id;
    this.processingStart = Date.now();
    this.processingLimit = 1500;
    this.states = ["success", "error", "processing", "clear"];
    this.fields = this.processFields();
    this.droplets = this.fields.filter((field) => field.isDroplet());
    this.saveListener();
    this.registerButtons();
    window.onbeforeunload = (e) => {
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
  // Filter for determining if inside of a Deck field
  insideDeck(node) {
    return node.parentNode.closest("fieldset.deck-box") ? true : false;
  }
  // Check to see if the object is a HTML node.
  isDomNode(node) {
    return typeof node === "object" && "nodeType" in node && node.nodeType === 1;
  }
  // Set the form via a DOM element or selector string
  setForm(formRef) {
    switch (typeof formRef) {
      case "string":
        return document.getElementById(formRef);
      case "object":
        if (this.isDomNode(formRef)) {
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
    const fields = Array.from(this.form.getElementsByClassName("form-field")).filter((field) => !this.insideDeck(field));
    const fieldObjects = [];
    fields.forEach((field) => {
      const object = this.generateFieldObject(field);
      if (object === null)
        return;
      fieldObjects.push(object);
      field.addEventListener("change", (e) => this.unsaved());
    });
    return fieldObjects;
  }
  registerButton(buttonClass, callback) {
    const buttons = Array.from(this.form.getElementsByClassName(buttonClass));
    buttons.forEach((button) => {
      button.addEventListener("click", (event) => {
        event.preventDefault();
        if (typeof callback === "function")
          callback(button);
        return false;
      });
    });
  }
  registerButtons() {
    this.registerButton("cms-save", () => this.save());
    this.registerButton("cms-delete", () => this.delete());
  }
  generateFieldObject(field) {
    const options = JSON.parse(field.dataset.options || "{}");
    options.form = this;
    switch (field.dataset.type) {
      case "text":
      case "url":
      case "hidden":
      case "email":
        return new TotalField(field, options);
      case "id":
        return this.initIdentifier(field, options);
      default:
        console.warn("Unknown field", field);
        return null;
    }
  }
  initIdentifier(field, options) {
    this.id = new Identifier(field, options);
    field.addEventListener("change", (event) => this.updateIdentifier());
    return this.id;
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
  // Submit functions
  //-------------------------
  saveListener() {
    this.form.addEventListener("submit", (event) => {
      event.preventDefault();
      this.save();
    });
    document.addEventListener("keydown", (event) => {
      if (this.isUnsaved()) {
        if (event.key === "s" && (event.ctrlKey || event.metaKey)) {
          event.preventDefault();
          this.save();
        }
      }
    });
  }
  save() {
    this.updateIdentifier();
    this.processing();
    this.api.postAPI(this.baseapi, this.generateData()).then((response) => this.afterSave(response)).catch((error) => this.error(error));
  }
  delete() {
    if (!this.isEditMode())
      return;
    if (window.confirm("Are you sure that you want to delete this? This cannot be undone.")) {
      this.updateIdentifier();
      this.processing();
      this.options.editAction = "redirect";
      this.options.editLink = location.origin + location.pathname;
      this.api.postAPI(`/collections/${this.collection}/${this.id}`, {}, "DELETE").then((response) => this.afterSave(response)).catch((error) => this.error(error));
    }
  }
  submit() {
    this.save();
  }
  updateIdentifier() {
    this.id = this.id.id;
  }
  // onSubmit(callback) {
  //     this.form.addEventListener("submit", event => {
  //         event.preventDefault();
  //         if (typeof callback === "function") callback();
  //     });
  // }
  afterSave(response) {
    if (!response)
      return;
    if (this.droplets.length > 0) {
      this.saveDroplets(() => this.afterSaveAction(response));
    } else {
      this.afterSaveAction(response);
    }
  }
  afterSaveAction(response) {
    this.success();
    const waitUntilSaved = /* @__PURE__ */ __name(() => {
      if (!this.saving()) {
        this.fields.forEach((field) => field.saved());
        return this.isEditMode() ? this.runEditAction() : this.runNewAction();
      }
      window.setTimeout(waitUntilSaved, 100);
    }, "waitUntilSaved");
    waitUntilSaved();
  }
  runAction(action, url) {
    switch (action) {
      case "refresh":
        location.reload(true);
        break;
      case "redirect-object":
        document.location = url + this.id;
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
  }
  runEditAction() {
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
    this.droplets.forEach((droplet) => droplet.autoProcessQueue());
  }
  saving() {
    const current = this.states.filter((state) => this.form.classList.contains(state));
    return current.length > 0;
  }
  changeState(newState) {
    const remove = this.states.filter((e) => e !== newState);
    const elements = [this.indicator, this.form];
    for (const element of elements) {
      if (newState)
        element.classList.add(newState);
      element.classList.remove(...remove);
    }
  }
  delayProcessing(callback) {
    const processingTime = Date.now() - this.processingStart;
    const delay = this.processingLimit - processingTime;
    window.setTimeout(() => {
      if (typeof callback === "function")
        callback();
    }, delay);
  }
  error(error) {
    console.error("Form Error: " + error);
    this.delayProcessing(() => {
      this.changeState("error");
    });
  }
  clear() {
    this.changeState("clear");
    window.setTimeout(() => {
      this.changeState();
    }, 1e3);
  }
  success() {
    this.delayProcessing(() => {
      this.changeState("success");
      this.form.classList.remove("unsaved");
      this.fields.forEach((field) => field.saved());
      window.setTimeout(() => {
        this.clear();
      }, 2e3);
    });
  }
  processing() {
    this.processingStart = Date.now();
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
    this.droplets.forEach((droplet) => droplet.updateUri());
  }
  // We only want to process the droplet queue after the inital
  // post request to create the object has been saved
  saveDroplets(callback) {
    let dropletCount = this.droplet.length;
    const dropletComplete = /* @__PURE__ */ __name((callback2) => {
      dropletCount--;
      if (dropletCount === 0) {
        if (typeof callback2 === "function")
          callback2();
      }
    }, "dropletComplete");
    this.droplets.forEach((droplet) => {
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
    this.fields.forEach((field) => {
      data[field.name] = field.getValue();
    });
    return data;
  }
};
__name(TotalForm, "TotalForm");

// javascript/admin.js
var forms = Array.from(document.querySelectorAll("form.totalform"));
for (const form of forms) {
  const totalform = new TotalForm(form);
}
//# sourceMappingURL=admin.js.map
