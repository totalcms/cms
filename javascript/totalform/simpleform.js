import TotalCMS from '../totalcms';

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

		this.method  = this.form.dataset.method || "POST";
		this.route   = this.form.dataset.route;
		this.api     = new TotalCMS({ url: this.form.dataset.api});
		this.refresh = this.form.dataset.refresh === "true";
		this.ajax    = this.form.dataset.ajax    === "true";
		this.button  = this.form.querySelector("button");

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
		if (this.refresh) {
			window.location.reload();
		}
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

			setTimeout(() => {
				this.button.classList.remove(state);
				this.button.textContent = originalText;
				this.button.style.width = "";
				this.refreshPage();
			}, 4000);
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
		return data;
	}
}
