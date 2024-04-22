import TotalField from "./totalfield";
import Dialog from "./dialog";
import Droplet from "./droplet";
import Details from "./details";

//-----------------------------------------------
// Total CMS Droplet
//-----------------------------------------------
export default class ImageField extends TotalField {

    constructor(container, options) {
        super(container, options);

		this.droplet    = this.setupDroplet();
		this.editDialog = this.setupEditDialog();
		this.linkDialog = this.setupLinkDialog();
    }


	apiUploadImage() {
		const api = `/collections/${this.form.collection}/${this.form.id}/${this.property}`;
		return this.api.apiUrl(api);
    }

	autoProcessQueue() {
		this.droplet.autoProcessQueue();
	}

	removeListeners() {
		if (this.documentListeners) {
			for (const key in this.documentListeners) {
				document.removeEventListener(key, this.documentListeners[key]);
			}
		}
	}

	setupDroplet() {
		return new Droplet(this, {
			paramName        : this.property,
			apiUrl           : this.apiUploadImage(),
			autoProcessQueue : this.form.isEditMode(),
			acceptedFiles    : "image/*",
		});
	}

	setupLinkDialog() {
		return new Dialog(this.container.querySelector(".image-link-dialog"), {
			open  : this.container.querySelector(".actionbar .links"),
			close : ".close",
		});
	}

	setupEditDialog() {
		return new Dialog(this.container.querySelector(".image-edit-dialog"), {
			open  : this.container.querySelector(".actionbar .edit"),
			close : ".close",
			onOpen : () => {
				this.setupEditAccordion();
				this.setupFocalPoint();
			},
			onClose : () => {
				this.removeListeners();
			}
		});
	}

	setupEditAccordion() {
		if (this.editAccordion) return;
		// Close other details when one is opened
		const details = Array.from(this.editDialog.dialog.querySelectorAll("details"));
		this.editAccordion = new Details(details);
	}

	setupFocalPoint() {
		const focalPointX = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-x])');
		const focalPointY = this.editDialog.dialog.querySelector('.form-field:has([name=focalpoint-y])');
		const focalPoint  = this.editDialog.dialog.querySelector('.focal-point');
		const focalPointCoords = (event) => {
			const clientX = event.touches ? event.touches[0].clientX : event.clientX;
			const clientY = event.touches ? event.touches[0].clientY : event.clientY;
			const rect = focalPoint.parentNode.getBoundingClientRect();
			let x = (clientX - rect.left) / rect.width * 100;
			let y = (clientY - rect.top) / rect.height * 100;
			if (x < 0) x = 0;
			if (x > 100) x = 100;
			if (y < 0) y = 0;
			if (y > 100) y = 100;
			return { x, y };
		};
		focalPointX.totalfield.watch(() => {
			focalPoint.style.left = `${focalPointX.totalfield.getValue()}%`;
		});
		focalPointY.totalfield.watch(() => {
			focalPoint.style.top = `${focalPointY.totalfield.getValue()}%`;
		});
		let dragging = false;

		const startDragging = () => { dragging = true };
		const stopDragging  = () => { if (dragging) dragging = false };
		const moveFocalPoint = (event) => {
			if (dragging) {
				event.preventDefault();
				const { x, y } = focalPointCoords(event);
				focalPoint.style.left = `${x}%`;
				focalPoint.style.top = `${y}%`;
				focalPointX.totalfield.setValue(x.toFixed(1));
				focalPointY.totalfield.setValue(y.toFixed(1));
			}
		};

		this.documentListeners = {
			"mousemove" : document.addEventListener('mousemove', moveFocalPoint),
			"touchmove" : document.addEventListener('touchmove', moveFocalPoint, { passive: false }),
		};

		focalPoint.addEventListener('mousedown', startDragging);
		focalPoint.addEventListener('touchstart', startDragging, { passive: true });

		focalPoint.addEventListener('mouseup', stopDragging);
		focalPoint.addEventListener('touchend', stopDragging, { passive: true });
	}

    getValue() {
		const fields = this.container.getElementsByClassName("form-field");
		const imageData = {};
		for (const field of fields) {
			let key = field.totalfield.property;
			const value = field.totalfield.getValue();

			if (key.startsWith("exif-")) {
				key = key.replace("exif-","");
				if (!imageData["exif"]) {
					imageData["exif"] = {};
				}
				imageData["exif"][key] = value;

			} else if (key.startsWith("focalpoint-")) {
				key = key.replace("focalpoint-","");
				if (!imageData["focalpoint"]) {
					imageData["focalpoint"] = {};
				}
				imageData["focalpoint"][key] = value;

			} else if (key.startsWith("palette-")) {
				if (!imageData["palette"]) {
					imageData["palette"] = [];
				}
				imageData["palette"].push(value);

			} else {
				imageData[key] = value;
			}
		}
        return imageData;
    }

    setValue(image) {
		// TODO: populate the fields with the image data
    }

	schema() {
        return {
            type     : "object",
            fieldset : this.options.type
        };
    }
}
