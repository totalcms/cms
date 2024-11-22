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

    getSelected() {
        return this.browser.querySelector(".selected");
    }

    getPath() {
        let item = this.getSelected();
        if (!item) return "";
        // If the item is a folder, get the path from the parent folder element
        if (item.classList.contains("folder")) item = item.parentNode.parentNode;
        const folder = item.closest("details")?.querySelector(".folder");
        return folder ? folder.dataset.path : "";
    }

    getFullPath() {
        let item = this.getSelected();
        if (!item) return "";
        const folder = item.closest("details")?.querySelector(".folder");
        return folder ? folder.dataset.path : "";
    }

    isCollectionProtected() {
		return this.container.querySelector("[name=protected]")?.checked;
	}

	isPasswordProtected() {
		return this.container.querySelector("[name=password]")?.value !== "";
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
        const selected = this.getSelected();
        selected.classList.contains("folder") ? this.actionEditFolder(selected) : this.actionEditFile(selected);
    }

    actionEditFile(file) {
        const dialogNode = file.querySelector(".file-edit-dialog");
        const dialog = this.initEditDialog(dialogNode);
        dialog.open();
    }

    actionEditFolder(folder) {
        const dialogNode = folder.parentNode.querySelector(".folder-edit-dialog");
        const dialog = this.initEditDialog(dialogNode);
        dialog.open();
    }

    initEditDialog(node) {
        return new Dialog(node, {
            open  : null,
            close : ".close",
            onOpen : () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
                // Setup Accordion
                const details = Array.from(node.querySelectorAll("details"));
                if (details.length > 0) new Details(details);
            },
            onClose : () => {
                this.dialogOpened = false;
                // this.updateLabel();
                // this.totalfield.autosave();
            }
        });
    }

    actionLinks() {
        const selected = this.getSelected();
        const dialogNode = selected.querySelector(".file-links-dialog");
        const dialog = this.initLinksDialog(dialogNode);
        dialog.open();
    }

    initLinksDialog(node) {
        return new Dialog(node, {
			open  : null,
			close : ".close",
			onOpen : () => {
				const iframe = node.querySelector("iframe");
				if (!iframe.src) iframe.src = iframe.dataset.src;
			},
		});
    }

    actionDownload() {
        const selected = this.getSelected();
        const name     = this.getFileAttribute(selected, "name");
        const path     = this.getPath();

        if (!name) {
            console.warn("No file selected");
            return;
        }

        const options = path.length > 0 ? {path:path} : {};
        const downloadApi = `/download/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
        const downloadUrl = this.api.buildApiQuery(downloadApi, options);

        // If the file is password protected, open the download in a new tab
        // so the user can enter the password
        if (this.isPasswordProtected()|| this.isCollectionProtected()) {
            window.open(downloadUrl, '_blank');
            return;
        }

        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = this.getValue().download; // Suggest a filename for the downloaded file
        document.body.appendChild(link); // Append the anchor element to the body
        link.click(); // Programmatically click the anchor element
        document.body.removeChild(link); // Remove the anchor element from the body
    }

    actionAddFolder() {
        const modal = this.container.querySelector(".folder-add-dialog");
        const path = this.getFullPath();

		const pathInput = modal.querySelector("[name=addpath]")
		pathInput.value = path.length > 0 ? path+"/" : "";

		const dialog = new Dialog(modal);
        dialog.open();

		const addFolderApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/folder`;

		modal.querySelector("button").addEventListener("click", () => {
			const newFolder = pathInput.value;
            this.form.api.postAPI(addFolderApi, {path:newFolder}).then(response => {
				this.addFolder(newFolder);
				dialog.close();
            });
		});
    }

	addFolderToBrowser(folder) {
		const path = folder.split("/");
		let currentPath = "";
		path.forEach(dir => {
			const lastPath = currentPath;
			currentPath += dir;

			if (!this.browser.querySelector(`[data-path="${currentPath}"]`)) {
				const folderNode = document.createElement("details");
				folderNode.innerHTML = `<summary class="folder" data-path="${currentPath}">${dir}</summary>`;
				this.browser.appendChild(folderNode);
			}

			currentPath += "/";
		}
		);
	}

    actionTrash() {
        const selected = this.getSelected();
        const type     = selected.classList.contains("folder") ? "folder" : "file";
        return type === "file" ? this.trashFile(selected) : this.trashFolder(selected);
    }

    trashFile(file) {
        const name = this.getFileAttribute(file, "name");
        const path = this.getPath();

        let deleteApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
        if (path.length > 0) deleteApi += `?path=${path}`;

        if (confirm("Are you sure that you want to delete this file? This cannot be undone.")) {
            this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
                file.remove();
                return this.resetPreview();
            });
        }
    }

    trashFolder(folder) {
        const name = folder.textContent;
        const path = this.getPath();

        let deleteApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
        if (path.length > 0) deleteApi += `?path=${path}`;

        const message = "Are you sure that you want to delete this folder and all of its contents?"
            + `This cannot be undone. Type the folder name to confirm this action. "${name}"`;
        if (prompt(message) === name) {
            this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
                folder.closest("li").remove();
                return this.resetPreview();
            });
        } else {
            alert("Folder name entered does not match. Deletion cancelled.");
        }
    }

    initBrowser() {
        this.files   = this.browser.querySelectorAll(".file");
        this.folders = this.browser.querySelectorAll(".folder");

        this.files.forEach(file => {
            file.addEventListener("click", this.selectFile.bind(this));
            file.addEventListener("dblclick", this.selectAndEditFile.bind(this));
        });
        // Not adding dblclick event to folders because it was causing the browser to freeze up
        // Possibly too many click events? Need to investigate further
        this.folders.forEach(folder => folder.addEventListener("click", this.selectFolder.bind(this)));
    }

    selectAndEditFile(event) {
        const file = event.currentTarget;
        const fileParent = file.parentNode;
        this.selectItem(fileParent);
        this.actionEditFile(fileParent);
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
        return field.totalfield ? field.totalfield.getValue() : null;
    }

    sprintf(format, ...args) {
        return format.replace(/%(\d+)/g, (match, number) => {
            return typeof args[number - 1] !== 'undefined' ? args[number - 1] : match;
        });
    }

	schema() {
        return {
            type     : "depot",
            fieldset : this.type
        };
    }
}
