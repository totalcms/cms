/**
 * Total CMS QuickAction
 *
 *
 * Quickly launch a CMA API request with a simple link
 *
 * <a href="/api/endpoint" class="cms-quick-action" data-method="PUT">Reindex</a>
 *
**/
export default class QuickAction {

    constructor(link, options={}) {
        if (link.tagName !== 'A') {
            throw new Error('QuickAction must be an anchor element');
        }
        if (link.quickaction) {
            return link.quickaction;
        }

        this.link     = link;
        this.url      = link.getAttribute('href');
        this.method   = link.dataset.method || 'GET';
        this.confirm  = link.dataset.confirm || false;
        this.redirect = link.dataset.redirect || false;
        this.reload   = link.dataset.hasOwnProperty('reload');

        this.api = new TotalCMS({ url: this.url });

        this.link.addEventListener('click', this.onClick.bind(this));
        this.link.quickaction = this;
    }

    onClick(e) {
        e.preventDefault();
        if (this.confirm) {
            if (!confirm(this.confirm)) {
                return;
            }
        }
        this.api.postAPI("", {}, this.method).then(json => this.processResponse(json)).catch(err => {
            console.error(err);
            this.link.style.color = 'oklch(var(--totalform-error))';
            this.dispatchEvent('quickaction-error', { error: err });
        });
    }

    dispatchEvent(name, detail) {
        const event = new CustomEvent(name, {
            detail: {
                url    : this.url,
                method : this.method,
                ...detail
            },
            bubbles : true,
        });
        this.link.dispatchEvent(event);
    }

    processResponse(json) {
        this.dispatchEvent('quickaction-success', { data: json });

        if (this.redirect) {
            document.location.href = this.redirect;
        }
        if (this.reload) {
            document.location.reload();
        }
    }
}
