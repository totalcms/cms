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

        this.link   = link;
        this.url    = link.getAttribute('href');
        this.method = link.dataset.method || 'GET';

        this.api = new TotalCMS({ url: this.url });

        this.link.addEventListener('click', this.onClick.bind(this));
    }

    onClick(e) {
        e.preventDefault();
        this.api.postAPI("", {}, this.method).then(json => document.location.reload());
    }
}
