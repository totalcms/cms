import TotalCMS from '../totalcms';
import FieldVisibility from './field-visibility';

//-----------------------------------------------
// Total CMS Simple Form constructor
//-----------------------------------------------
export default class SimpleForm {

    // Constructors
    constructor(formRef, options = {}) {
        this.form = this.setForm(formRef);
		formRef.simpleform = this;

		if (!formRef || !this.form) {
			console.error("form not found");
			return false;
		}

		// Create lightweight field wrappers for visibility support
		this.fields = this.createFieldWrappers();

		this.method  = this.form.dataset.method || "POST";
		this.route   = this.form.dataset.route;
		this.api     = new TotalCMS({ url: this.form.dataset.api});
		this.refresh = this.form.dataset.refresh === "true";
		this.ajax    = this.form.dataset.ajax    === "true";
		this.confirm = this.form.dataset.confirm || "";
		this.button  = this.form.querySelector("button[type=submit]");

		if (this.ajax) {
			this.form.addEventListener("submit", event => event.preventDefault());
			this.button.addEventListener("click", event => {
				event.preventDefault();
				this.send();
			});
		} else {
			this.form.method = this.method;
			this.form.action = this.route;
			this.button.addEventListener("click", event => {
				this.form.submit();
			});
		}

		// Initialize field visibility if there are fields
		if (this.fields.length > 0) {
			this.visibility = new FieldVisibility(this.form, this.fields);
			this.visibility.initialize();
		}
    }

	//-------------------------
	// Lightweight Field Wrappers
	//-------------------------
	createFieldWrappers() {
		const fieldContainers = Array.from(this.form.querySelectorAll('.form-field'));
		return fieldContainers.map(container => this.createFieldWrapper(container));
	}

	createFieldWrapper(container) {
		const input = container.querySelector('input,textarea,select');
		const property = input ? input.name : '';

		return {
			container: container,
			property: property,
			input: input,

			getValue() {
				if (!input) return '';

				switch (input.type) {
					case 'checkbox':
						return input.checked;
					case 'radio':
						const checked = container.querySelector('input[type="radio"]:checked');
						return checked ? checked.value : '';
					default:
						return input.value;
				}
			},

			show() {
				container.style.display = '';
				container.classList.remove('field-hidden');
				container.classList.add('field-visible');
				if (input && input.hasAttribute('data-original-required')) {
					input.required = true;
				}
			},

			hide() {
				container.style.display = 'none';
				container.classList.remove('field-visible');
				container.classList.add('field-hidden');
				if (input && input.required) {
					input.setAttribute('data-original-required', 'true');
					input.required = false;
				}
				if (input) {
					input.setCustomValidity("");
				}
				container.classList.remove("error");
			}
		};
	}

    isDomNode(node){
        return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
    }

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

	hasFileField() {
		return this.form.querySelector("input[type=file]");
	}

	send() {
		if (this.confirm && !window.confirm(this.confirm)) {
			return;
		}

		this.button.classList.remove("success", "error");
		this.button.classList.add("processing");

		if (this.hasFileField()) {
			this.api.postFileAPI(this.route, new FormData(this.form), this.method)
				.then(response => this.success(response))
				.catch(error => this.error(error));
			return;
		}

        this.api.postAPI(this.route, this.generateData(), this.method)
            .then(response => this.success(response))
            .catch(error => this.error(error));
    }

	success(response) {
		this.toggleButton("success", "🥳");
		this.dispatchSuccess(response);
		if (this.form.totalform) {
			this.form.totalform.changeState("success");
			this.form.totalform.fields.forEach(field => field.saved());
		}
	}

	refreshPage() {
		if (this.refresh && !this.hasError()) {
			window.location.reload();
		}
	}

	hasError() {
		return this.button.classList.contains("error");
	}

	error(error) {
		console.log("Form error", error);
		this.toggleButton("error", "😭");
	}

	toggleButton(state, label) {
		setTimeout(() => {
			const originalText = this.button.textContent;
			this.button.style.width = `${this.button.offsetWidth}px`;
			this.button.classList.remove("processing");
			this.button.classList.add(state);
			this.button.textContent = label;

			this.button.addEventListener("pointermove", () => {
				this.button.classList.remove(state);
				this.button.textContent = originalText;
				this.button.style.width = "";
			}, { once: true });

			if (!this.hasError()) {
				setTimeout(() => this.refreshPage(), 1500);
			}
		}, 200);
	}

	dispatchSuccess(response) {
		const event = new CustomEvent("simpleform:success", {
			detail: {
				form: this.form,
				data: response
			}
		});
		this.form.dispatchEvent(event);
	}

	generateData() {
		const data = {};
		new FormData(this.form).forEach((value, key) => {
			data[key] = value;
		});

		// FormData doesn't include unchecked checkboxes/toggles, so we need to explicitly add them
		const checkboxes = this.form.querySelectorAll('input[type="checkbox"]');
		checkboxes.forEach(checkbox => {
			if (!data.hasOwnProperty(checkbox.name)) {
				data[checkbox.name] = false;
			} else {
				// Convert checkbox values to boolean
				data[checkbox.name] = checkbox.checked;
			}
		});

		return data;
	}
}
