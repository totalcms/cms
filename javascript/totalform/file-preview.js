import Dialog from "./dialog";
import Details from "./details";

//-----------------------------------------------
// Total CMS File Droplet
//-----------------------------------------------
export default class FilePreview {

    constructor(container, totalfield) {
		if (container.preview) return container.preview;

		this.container  = container;

		this.container.preview = this;

		this.api        = totalfield.api;
		this.form       = totalfield.form;
		this.property   = totalfield.property;
		this.type       = totalfield.type;
		this.totalfield = totalfield;

		this.fields = this.container.getElementsByClassName("form-field");

		this.editDialog = this.setupEditDialog();
		this.linkDialog = this.setupLinkDialog();

		this.setupActionBar();
		this.updatePreview();
    }

	isDepot() {
		return this.type === "depot";
	}

	updatePreview() {
		this.updateIcon();
		this.updateLabel();
	}

	updateIcon() {
		const icon = this.container.querySelector(".file-icon");
		const mime = this.container.querySelector("[name=download]").value.toLowerCase();
		const ext  = mime.split(".").pop();
		icon.className = `file-icon icon-${ext}`;
	}

	updateLabel() {
		const label = this.container.querySelector(".filename");
		const name  = this.container.querySelector("[name=download]").value;
		label.textContent = name;
	}

	setupActionBar() {
		const edit  = this.container.querySelector(".actionbar .edit");
		edit.addEventListener("click", event => {
			event.preventDefault();
			this.editDialog.open();
		});
		const links = this.container.querySelector(".actionbar .links");
		links.addEventListener("click", event => {
			event.preventDefault();
			this.linkDialog.open();
		});
		this.setupDelete();
		this.setupDownload();
	}

	setupDownload() {
		const downloadButton = this.container.querySelector(".actionbar .download");
		if (downloadButton) {
			downloadButton.addEventListener("click", event => {
				event.preventDefault();
				let downloadApi = `/download/${this.form.collection}/${this.form.id}/${this.property}`;
				if (this.isDepot()) {
					const name = this.getValue().name;
					downloadApi = `/download/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
				}
				const downloadUrl = this.api.buildApiQuery(downloadApi);

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
			});
		}
	}

	isCollectionProtected() {
		return this.container.querySelector("[name=protected]").checked;
	}

	isPasswordProtected() {
		return this.container.querySelector("[name=password]").value !== "";
	}

	setupDelete() {
		const deleteButton = this.container.querySelector(".actionbar .trash");
		if (deleteButton) {
			deleteButton.addEventListener("click", event => {
				event.preventDefault();
				if (confirm("Are you sure that you want to delete this file?")) {
					let deleteApi = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
					if (this.isDepot()) {
						const name = this.getValue().name;
						deleteApi  = `/collections/${this.form.collection}/${this.form.id}/${this.property}/${name}`;
					}
					this.form.api.postAPI(deleteApi, "", "DELETE").then(response => {
						this.container.remove();
					});
				}
			});
		}
	}

	setupLinkDialog() {
		return new Dialog(this.container.querySelector(".file-links-dialog"), {
			open  : null,
			close : ".close",
			onOpen : () => {
				const iframe = this.linkDialog.dialog.querySelector("iframe");
				if (!iframe.src) {
					iframe.src = iframe.dataset.src;
				}
			},
		});
	}

	setupEditDialog() {
		// process the form fields added in the edit dialog
		this.form.processFields();
		return new Dialog(this.container.querySelector(".file-edit-dialog"), {
			open  : null,
			close : ".close",
			onOpen : () => {
				if (this.dialogOpened) return;
				this.dialogOpened = true;
				this.setupEditAccordion();
			},
			onClose : () => {
				this.dialogOpened = false;
				this.updateLabel();
				this.totalfield.autosave();
			}
		});
	}

	setupEditAccordion() {
		// Close other details when one is opened
		const details = Array.from(this.editDialog.dialog.querySelectorAll("details"));
		this.editAccordion = new Details(details);
	}

    getValue() {
		const fileData = {};
		for (const field of this.fields) {
			let key = field.totalfield.property;
			const value = field.totalfield.getValue();
			fileData[key] = value;
		}

        return fileData;
    }

	clearValue() {
		for (const field of this.fields) {
			field.totalfield.clearValue();
		}
	}

    setValue(file) {
		// from tests this.fields updates as colors are dragged in the palette
		for (const field of this.fields) {
			const key = field.totalfield.property;
			field.totalfield.setValue(file[key]||"");
			// setting to saved state since this data comes from the server
			field.totalfield.saved();
		}
		this.updatePreview();
    }
}
