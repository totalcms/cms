import Details from "./details";
import Dialog from "./dialog";
import TotalField from "./totalfield";
import DepotDroplet from "./droplet-depot";

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

        this.draggedItems = [];

        this.initBrowser();
        this.initActionBar();
        this.setupProtectDialog();
        this.setupDroplet();
        this.initKeyboardNavigation();
    }

    setupProtectDialog() {
        return new Dialog(this.container.querySelector(".protection-dialog"), {
            open  : this.container.querySelector("button.protect"),
            close : ".close",
            onOpen : () => {
                if (this.dialogOpened) return;
                this.dialogOpened = true;
            },
            onClose : () => {
                this.dialogOpened = false;
                // this.totalfield.autosave();
            }
        });
    }

    setupDroplet() {
		this.droplet = new DepotDroplet(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadFile(),
			autoProcessQueue : this.form.isEditMode(),
			acceptedFiles    : null,
			chunking         : true,
			singleMode       : false,
			rules            : this.options.rules,
		});
		this.droplet.onQueueComplete(() => this.uploadComplete());
	}

    fileAdded(file) {
        this.form.processFields();
        file.path = this.getPath();
	}

    fileUploaded(file, response) {
		console.log("DepotField.fileUploaded()", file, response);

        const path  = file.path ?? "";
        let   files = response.data[this.property].files;

        if (path.length > 0) {
            const folders = path.split("/");
            folders.forEach(folder => {
                files = files.filter(f => f.name === folder).shift().files;
            });
        }
        this.initBrowser();

		const data = files.filter(f => f.mime !== 'folder').sort((a, b) => a.uploadDate < b.uploadDate ? 1 : -1).shift();
        this.updateNewFileMeta(file, data);
	}

    updateNewFileMeta(file, data) {
        file.previewElement.querySelector(".file").textContent = data.name;
        file.previewElement.querySelector(".size").textContent = this.bytesToString(data.size);

        for (const key in data) {
            this.setFileAttribute(file.previewElement, key, data[key]);
        }
        this.setFileLinksUrl(file.previewElement, data);
    }

    uploadComplete() {
		// this is a hack to mark the field and form as saved
		// When a new file finishes uploading, a preview is created
		// and the field is marked as unsaved. This timeout is to give
		// the preview time to be created before marking the field as saved
		setTimeout(() => {
			this.saved();
			this.form.uploadComplete();
		}, 1000);
	}

	apiUploadFile() {
        const api     = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
        const path    = this.getPath();
        const options = path.length > 0 ? {path:path} : {};
		return this.api.buildApiQuery(api, options);
    }

	updateAPIUrl() {
		this.droplet.updateUrl(this.apiUploadFile());
	}

    getSelected() {
        return this.browser.querySelector(".selected");
    }

    getParentPath() {
        return this.getPath(true);
    }

    getPath(parent = false) {
        let item = this.getSelected();
        if (!item) return "";


        if (item.classList.contains("folder")) {
            if (!parent) return item.dataset.path;

            // If the item is a folder, get the path from the parent folder element
            if (item.classList.contains("folder")) item = item.parentNode.parentNode;
        }

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
        const dialogNode = this.container.querySelector(".folder-edit-dialog");
        dialogNode.querySelector("[name=name]").value = folder.textContent;

        const dialog = new Dialog(dialogNode);
        dialog.open();

		// TODO: Handle folder rename - make sure folder name is not blank
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

        const button = modal.querySelector("button");

		const dialog = new Dialog(modal);
        dialog.open();

		const addFolderApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}/folder`;

        pathInput.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                button.click();
            }
        });

		button.addEventListener("click", () => {
			const newFolder = pathInput.value;
            this.form.api.postAPI(addFolderApi, {path:newFolder}).then(response => {
				this.addFolderToBrowser(newFolder);
				dialog.close();
            });
		});
    }

	addFolderToBrowser(folder) {
		const path = folder.split("/");
		let currentPath = "";
		path.forEach(dir => {
            const lastPath = currentPath.slice(0, -1); // Remove trailing slash
			currentPath += dir;

			if (!this.browser.querySelector(`[data-path="${currentPath}"]`)) {
                const template = this.container.querySelector(".folder-template").content.cloneNode(true);
                const folder   = template.querySelector(".folder");

                folder.textContent  = dir;
                folder.dataset.path = currentPath;

                if (lastPath.length === 0) {
                    this.browser.prepend(template);
                } else {
                    const parentFolder = this.browser.querySelector(`[data-path="${lastPath}"]`);
                    parentFolder.parentNode.querySelector(".folder-contents").prepend(template);
                }
			}

			currentPath += "/";
		});
        this.initBrowser();
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
        const path = this.getParentPath();

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

    initKeyboardNavigation() {
        this.browser.setAttribute("tabindex", "0");
        this.browser.addEventListener("keydown", this.handleKeyNavigation.bind(this));
    }

    handleKeyNavigation(event) {
        const validKeys = ["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight"];
        if (!validKeys.includes(event.key)) return;

        event.preventDefault();

        const selected = this.getSelected();
        if (!selected) {
            const items = this.getNavigableItems();
            if (items.length > 0) this.selectItem(items[0]);
            return;
        }

        switch (event.key) {
            case "ArrowUp":   this.navigateUp(selected);    break;
            case "ArrowDown": this.navigateDown(selected);  break;
            case "ArrowLeft": this.navigateLeft(selected);  break;
            case "ArrowRight":this.navigateRight(selected); break;
        }
    }

    getNavigableItems() {
        const items = [];
        const walk = (container) => {
            for (const li of container.children) {
                if (li.tagName !== "LI") continue;
                const folder = li.querySelector(":scope > details > summary.folder");
                if (folder) {
                    items.push(folder);
                    const details = li.querySelector(":scope > details");
                    if (details && details.hasAttribute("open")) {
                        const contents = details.querySelector(".folder-contents");
                        if (contents) walk(contents);
                    }
                } else {
                    items.push(li);
                }
            }
        };
        walk(this.browser);
        return items;
    }

    navigateUp(selected) {
        const items = this.getNavigableItems();
        const index = items.indexOf(selected);
        if (index > 0) {
            this.selectItem(items[index - 1]);
            items[index - 1].scrollIntoView({block: "nearest"});
        }
    }

    navigateDown(selected) {
        const items = this.getNavigableItems();
        const index = items.indexOf(selected);
        if (index < items.length - 1) {
            this.selectItem(items[index + 1]);
            items[index + 1].scrollIntoView({block: "nearest"});
        }
    }

    navigateRight(selected) {
        if (!selected.classList.contains("folder")) return;
        const details = selected.closest("details");
        if (!details) return;

        if (!details.hasAttribute("open")) {
            details.setAttribute("open", "");
        } else {
            const contents = details.querySelector(".folder-contents");
            if (!contents) return;
            const items = this.getNavigableItems();
            const index = items.indexOf(selected);
            if (index < items.length - 1) {
                this.selectItem(items[index + 1]);
                items[index + 1].scrollIntoView({block: "nearest"});
            }
        }
    }

    navigateLeft(selected) {
        if (selected.classList.contains("folder")) {
            const details = selected.closest("details");
            if (details && details.hasAttribute("open")) {
                details.removeAttribute("open");
                return;
            }
        }
        const parentDetails = selected.closest("details")?.parentElement?.closest("details");
        if (parentDetails) {
            const parentFolder = parentDetails.querySelector(":scope > summary.folder");
            if (parentFolder) {
                this.selectItem(parentFolder);
                parentFolder.scrollIntoView({block: "nearest"});
            }
        }
    }

    initBrowser() {
        this.files   = this.browser.querySelectorAll(".file");
        this.folders = this.browser.querySelectorAll(".folder");

        this.files.forEach(file => {
            if (file.clickListener) return;
            file.addEventListener("click", this.selectFile.bind(this));
            file.addEventListener("dblclick", this.selectAndEditFile.bind(this));
            file.clickListener = true;
        });
        // Not adding dblclick event to folders because it was causing the browser to freeze up
        // Possibly too many click events? Need to investigate further
        this.folders.forEach(folder => {
            if (folder.clickListener) return;
            folder.addEventListener("click", this.selectFolder.bind(this))
            folder.clickListener = true;
        });

        this.initDragAndDrop();
    }

    initDragAndDrop() {
        // Make file <li> elements draggable
        this.browser.querySelectorAll("li").forEach(li => {
            if (li.dragInitialized) return;
            // Only files (li without a details child) are draggable
            if (this.is_folder(li)) return;
            li.draggable = true;
            li.addEventListener("dragstart", this.handleDragStart.bind(this));
            li.addEventListener("dragend", this.handleDragEnd.bind(this));
            li.dragInitialized = true;
        });

        // Drop targets: folder summaries
        this.folders.forEach(folder => {
            if (folder.dropInitialized) return;
            folder.addEventListener("dragover", this.handleFolderDragOver.bind(this));
            folder.addEventListener("dragleave", this.handleFolderDragLeave.bind(this));
            folder.addEventListener("drop", (e) => this.handleFolderDrop(e, folder));
            folder.dropInitialized = true;
        });

        // Drop target: root browser (drop outside any folder)
        if (!this.browser.dropInitialized) {
            this.browser.addEventListener("dragover", (e) => {
                if (this.draggedItems.length === 0) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = "move";
            });
            this.browser.addEventListener("drop", this.handleRootDrop.bind(this));
            this.browser.dropInitialized = true;
        }
    }

    handleDragStart(event) {
        const target = event.currentTarget;

        // If the dragged item is already selected, drag all selected files
        // Otherwise treat it as a single-item drag
        if (target.classList.contains("selected")) {
            this.draggedItems = Array.from(this.browser.querySelectorAll("li.selected")).filter(li => !this.is_folder(li));
        } else {
            this.draggedItems = [target];
        }

        this.draggedItems.forEach(item => item.classList.add("dragging"));
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", "");
    }

    handleDragEnd() {
        this.draggedItems.forEach(item => item.classList.remove("dragging"));
        this.draggedItems = [];
        this.browser.querySelectorAll(".drag-over").forEach(el => el.classList.remove("drag-over"));
    }

    handleFolderDragOver(event) {
        if (this.draggedItems.length === 0) return;
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = "move";
        event.currentTarget.classList.add("drag-over");
    }

    handleFolderDragLeave(event) {
        event.currentTarget.classList.remove("drag-over");
    }

    handleFolderDrop(event, folder) {
        event.preventDefault();
        event.stopPropagation();
        folder.classList.remove("drag-over");
        if (this.draggedItems.length === 0) return;

        const destPath = folder.dataset.path;
        const details  = folder.closest("details");
        const contents = details.querySelector(".folder-contents");

        this.draggedItems.forEach(item => {
            const sourcePath = item.closest("details")?.querySelector("summary.folder")?.dataset.path || "";
            if (sourcePath === destPath) return;

            const name = this.getFileAttribute(item, "name");
            contents.appendChild(item);

            if (this.form.isEditMode()) {
                this.moveFileAPI(name, sourcePath, destPath);
            }
        });

        details.setAttribute("open", "");
        this.clearSelection();
        this.resetPreview();
    }

    handleRootDrop(event) {
        event.preventDefault();
        if (this.draggedItems.length === 0) return;

        this.draggedItems.forEach(item => {
            const sourcePath = item.closest("details")?.querySelector("summary.folder")?.dataset.path || "";
            if (sourcePath === "") return;

            const name = this.getFileAttribute(item, "name");
            this.browser.appendChild(item);

            if (this.form.isEditMode()) {
                this.moveFileAPI(name, sourcePath, "");
            }
        });

        this.clearSelection();
        this.resetPreview();
    }

    moveFileAPI(name, sourcePath, destPath) {
        let api = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}/move`;
        if (sourcePath.length > 0) api += `?path=${sourcePath}`;
        this.form.api.postAPI(api, { destination: destPath }, "PUT");
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
        const multiSelect = event.metaKey || event.ctrlKey;

        if (multiSelect) {
            fileParent.classList.toggle("selected");
            const selected = this.browser.querySelectorAll("li.selected");
            if (selected.length === 0) return this.resetPreview();
            if (selected.length === 1) return this.updatePreview(selected[0]);
            return;
        }

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
        this.updateAPIUrl();
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

    setFileLinksUrl(file, data) {
        const frame = file.querySelector(".file-links-dialog iframe");
        const url = new URL(frame.dataset.src, window.location.origin);
        url.searchParams.set("name", data.name);
        frame.dataset.src = url.pathname + url.search;
    }

    setFileAttribute(file, attribute, value) {
        const input = file.querySelector(`[name=${attribute}]`)
        if (input) {
            const field = input.closest(".form-field");
            if (field.totalfield) field.totalfield.setValue(value);
        }
    }

    getFileAttribute(file, attribute) {
        const input = file.querySelector(`[name=${attribute}]`);
        if (!input) return null;
        const field = input.closest(".form-field");
        if (!field) return null;
        return field.totalfield ? field.totalfield.getValue() : null;
    }

    sprintf(format, ...args) {
        return format.replace(/%(\d+)/g, (match, number) => {
            return typeof args[number - 1] !== 'undefined' ? args[number - 1] : match;
        });
    }

    is_folder(item) {
        return item.firstChild.tagName === "DETAILS";
    }

    getFileData(file)
    {
        const data = {};
        for (const field of file.querySelectorAll(".form-field")) {
            const nameEl = field.querySelector("[name]");
            if (!nameEl) continue;
            const name = nameEl.name;
            // Use totalfield if available, otherwise fall back to raw input value
            data[name] = field.totalfield ? field.totalfield.getValue() : (nameEl.value || null);
        }
        return data;
    }

    getFolderData(folder)
    {
        const files = [];
        for (const item of folder.children) {
            if (this.is_folder(item)) {
                files.push({
                    name : item.querySelector(".folder").textContent,
                    mime : "folder",
                    files: this.getFolderData(item.querySelector(".folder-contents"))
                });
                continue;
            }
            // Skip files that are still being processed/uploaded (not yet on server)
            if (item.classList.contains("dz-processing")) continue;
            files.push(this.getFileData(item));
        }
        return files;
    }

    getValue() {
		const passwordInput = this.container.querySelector("[name=password]");
		const protectInput  = this.container.querySelector("[name=protected]");
		const password = passwordInput?.closest(".form-field");
		const protect  = protectInput?.closest(".form-field");
		const depot    = {
            "password"  : password?.totalfield?.getValue() ?? "",
            "protected" : protect?.totalfield?.getValue() ?? false,
            files       : this.getFolderData(this.browser),
        };
        return depot;
    }

    setValue(value) {
		console.warn("DepotField.setValue() is not implemented", value);
    }

	clearValue() {
		console.warn("DepotField.clearValue() is not implemented");
	}

	schema() {
        return {
            type     : "depot",
            fieldset : this.type
        };
    }
}
