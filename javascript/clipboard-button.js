/**
 * Total CMS Clipboard Button
 *
 *
 * Copy data from any element to the clipboard.
 *
 * <button class="cms-clip-button" data-clip="#nodeid">Copy to Clipboard</button>
 *
**/
export default class ClipButton {

    constructor(button, options={}) {
        const selector = button.dataset.clip;
        this.source = document.querySelector(selector);

        this.copiedText = options.copiedText || "Copied!";

        button.addEventListener('click', this.onClick.bind(this));
    }

    onClick(e) {
        e.preventDefault();
        const button = e.currentTarget;
        navigator.clipboard.writeText(this.source.textContent||this.source.value).then(() => {
            setTimeout(() => {
                const originalText = button.textContent;
                button.style.width = `${button.offsetWidth}px`;
                button.classList.add("copied");
                button.textContent = this.copiedText;

                setTimeout(() => {
                    button.classList.remove("copied");
                    button.textContent = originalText;
                    button.style.width = "";
                }, 2000);
            }, 200);
        })
        .catch(err => {
            console.warn('Could not copy macro: ', err);
        });
    }


}
