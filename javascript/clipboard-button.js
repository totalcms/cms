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
        const text = this.source.textContent || this.source.value;

        this.copyToClipboard(text)
            .then(() => this.showCopiedFeedback(button))
            .catch(err => console.warn('Could not copy: ', err));
    }

    copyToClipboard(text) {
        // Clipboard API requires HTTPS - use fallback for HTTP
        if (navigator.clipboard?.writeText) {
            return navigator.clipboard.writeText(text);
        }

        // Fallback for non-secure contexts (HTTP)
        return new Promise((resolve, reject) => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                resolve();
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(textarea);
            }
        });
    }

    showCopiedFeedback(button) {
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
    }


}
