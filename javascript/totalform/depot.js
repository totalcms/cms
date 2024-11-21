import Details from "./details";
import Dialog from "./dialog";
import TotalField from "./totalfield";

//-----------------------------------------------
// Total CMS Depot Droplet
//-----------------------------------------------
export default class DepotField extends TotalField {

    constructor(container, options) {
        super(container, options);

        this.browser       = this.container.querySelector(".depot-browser");
        this.folderPreview = this.container.querySelector(".folder-preview");
        this.filePreview   = this.container.querySelector(".file-preview");
        this.actionbar     = this.container.querySelector(".actionbar");

        this.initBrowser();
        this.initActionBar();
    }

    initActionBar() {
        this.actionbar.querySelector(".edit").addEventListener("click", this.actionEdit.bind(this));
        this.actionbar.querySelector(".links").addEventListener("click", this.actionLinks.bind(this));
        this.actionbar.querySelector(".download").addEventListener("click", this.actionDownload.bind(this));
        // this.actionbar.querySelector(".upload").addEventListener("click", this.actionUpload.bind(this));
        this.actionbar.querySelector(".add-folder").addEventListener("click", this.actionAddFolder.bind(this));
        this.actionbar.querySelector(".trash").addEventListener("click", this.actionTrash.bind(this));
    }

    actionEdit() {
        const selected = this.browser.querySelector(".selected");
        selected.classList.contains("folder") ? this.actionEditFolder(selected) : this.actionEditFile(selected);
    }

    actionEditFile(file) {
        const dialogNode = file.querySelector(".file-edit-dialog");
        const dialog = dialogNode.dialog || this.initEditDialog(dialogNode);
        dialog.open();
    }

    actionEditFolder(folder) {
        const dialogNode = folder.parentNode.querySelector(".folder-edit-dialog");
        const dialog = dialogNode.dialog || this.initEditDialog(dialogNode);
        dialog.open();
    }

    initEditDialog(node) {
		const details = Array.from(node.querySelectorAll("details"));
        if (details.length > 0) {
            const editAccordion = new Details(details);
        }

        return new Dialog(node, {
            open  : null,
            close : ".close",
            onOpen : () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
                // this.setupEditAccordion();
            },
            onClose : () => {
                this.dialogOpened = false;
                // this.updateLabel();
                // this.totalfield.autosave();
            }
        });
    }

    actionLinks() {
    }
    actionDownload() {
    }
    actionAddFolder() {
    }
    actionTrash() {
    }

    initBrowser() {
        this.files   = this.browser.querySelectorAll(".file");
        this.folders = this.browser.querySelectorAll(".folder");

        this.files.forEach(file => file.addEventListener("click", this.selectFile.bind(this)));
        this.folders.forEach(folder => folder.addEventListener("click", this.selectFolder.bind(this)));
    }

    selectFile(event) {
        const file = event.currentTarget;
        const fileParent = file.parentNode;
        // Give the ability to de-select a file by clicking it again
        if (fileParent.classList.contains("selected")) {
            this.clearSelection();
            return this.resetPreview();
        }
        this.selectItem(fileParent);
    }

    selectFolder(event) {
        const folder = event.currentTarget;
        if (folder.parentNode.hasAttribute("open") && !folder.classList.contains("selected")) {
            // Don't close the folder if it's already open and clicked
            event.preventDefault();
        }
        this.selectItem(folder);
    }

    selectItem(item) {
        this.clearSelection();
        item.classList.add("selected");
        this.updatePreview(item);
    }

    clearSelection() {
        this.browser.querySelectorAll(".selected").forEach(item => item.classList.remove("selected"));
    }

    resetPreview() {
        this.folderPreview.querySelector(".folder-name").textContent = "";
        this.filePreview.classList.add("cms-hide");
        this.folderPreview.classList.remove("cms-hide");
        this.enableActionBarButtons(["upload", "add-folder"]);
    }

    updatePreview(item) {
        item.classList.contains("folder") ? this.updateFolderPreview(item) : this.updateFilePreview(item);
    }

    updateFolderPreview(folder) {
        this.folderPreview.querySelector(".folder-name").textContent = folder.textContent;
        this.filePreview.classList.add("cms-hide");
        this.folderPreview.classList.remove("cms-hide");
        this.enableActionBarButtons(["upload", "add-folder", "trash", "edit"]);
    }

    updateFilePreview(file) {
        const name     = this.getFileAttribute(file, "name");
        const comments = this.getFileAttribute(file, "comments");
        const size     = this.getFileAttribute(file, "size") || 0;
        const count    = this.getFileAttribute(file, "count") || 0;
        const download = this.getFileAttribute(file, "download");
        const date     = this.getFileAttribute(file, "uploadDate");
        const tags     = this.getFileAttribute(file, "tags");
        const ext      = name.split(".").pop();

        this.filePreview.querySelector(".file-name").textContent     = name;
        this.filePreview.querySelector(".file-comments").textContent = comments;
        this.filePreview.querySelector(".file-download").textContent = download;
        this.filePreview.querySelector(".file-count").textContent    = count;
        this.filePreview.querySelector(".file-size").textContent     = this.bytesToString(size);
        this.filePreview.querySelector(".file-date").textContent     = new Date(date).toLocaleString();

        const tagNode = this.filePreview.querySelector(".file-tags");
        tagNode.innerHTML = "";
        tags.forEach(tag => {
            const tagElement = document.createElement("span");
            tagElement.textContent = tag;
            tagNode.appendChild(tagElement);
        });

        this.filePreview.querySelector(".file-icon").className = `file file-icon icon-${ext}`;

        this.folderPreview.classList.add("cms-hide");
        this.filePreview.classList.remove("cms-hide");
        this.enableActionBarButtons();
    }

    bytesToString(bytes) {
        const sizes = ["B", "KB", "MB", "GB", "TB"];
        const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
        return (bytes / Math.pow(1024, i)).toFixed(1) + " " + sizes[i];
    }

    enableActionBarButtons(classes = []) {
        this.actionbar.querySelectorAll("button").forEach(button => {
            if (classes.length === 0) {
                return button.disabled = false;
            }
            button.disabled = !classes.some(cls => button.classList.contains(cls));
        });
    }

    getFileAttribute(file, attribute) {
        const field = file.querySelector(`[name=${attribute}]`).closest(".form-field");
        return field.totalfield.getValue();
    }

	schema() {
        return {
            type     : "depot",
            fieldset : this.type
        };
    }
}
