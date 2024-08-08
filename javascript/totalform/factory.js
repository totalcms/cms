import TotalCMS from '../totalcms';

//-----------------------------------------------
// Total CMS Factory Form constructor
//-----------------------------------------------
export default class FactoryForm {

    // Constructors
    constructor(formRef, options = {}) {
        this.form = this.setForm(formRef);
		formRef.factoryform = this;

		if (!formRef || !this.form) {
			console.error("form not found");
			return false;
		}

		this.collection = this.form.dataset.collection;
		this.route      = `/import/${this.collection}/factory`;
		this.api        = new TotalCMS({ url: this.form.dataset.api});
		this.refresh    = this.form.dataset.refresh === "true";

		this.form.addEventListener("submit", event => event.preventDefault());

		this.button = this.form.querySelector("button");
		this.button.addEventListener("click", event => {
			event.preventDefault();
			this.send();
		});
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

	send() {
		this.button.classList.remove("success", "error");
		this.button.classList.add("processing");

        this.api.postAPI(this.route, this.generateData())
            .then(response => this.success(response))
            .catch(error => this.error(error));
    }

	success(response) {
		this.toggleButton("success", "🥳");
		if (this.refresh) {
			window.location.reload();
		}
	}

	error(error) {
		console.log("FactoryForm error", error);
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
			}, 2000);
		}, 200);
	}

	generateData() {
		const data = {};
		new FormData(this.form).forEach((value, key) => {
			data[key] = value;
		});
		return data;
	}
}
