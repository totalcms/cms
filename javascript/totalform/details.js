//-----------------------------------------------
// Total CMS Details Accordion with Animations
//-----------------------------------------------
export default class Details {
	// Define option defaults
	static defaults = {
		soloMode     : true,
		openFirst    : true,
		scrollOffset : 16,
		speed        : 200,
		animation    : 'ease',
	};

	constructor(containerOrDetails, options = {}) {
		// Backward compatibility: accept array of details or container element
		const isDetailsArray = Array.isArray(containerOrDetails) ||
			(containerOrDetails && containerOrDetails.nodeType === 1 && containerOrDetails.tagName.toLowerCase() === 'details');

		if (isDetailsArray) {
			// Old behavior: passed details elements directly
			this.details = Array.isArray(containerOrDetails) ? containerOrDetails : [containerOrDetails];
			this.container = this.details[0]?.parentElement;
		} else {
			// New behavior: passed container element
			if (containerOrDetails && containerOrDetails.details) return containerOrDetails.details;
			this.container = containerOrDetails;
			this.details = this.findDetails(containerOrDetails);
		}

		if (this.details.length === 0) {
			console.warn("No valid details elements found");
			return;
		}

		this.options = {
			...Details.defaults,
			...(this.container ? this._localOptions(this.container) : {}),
			...options
		};

		if (this._hasDetailHash()) {
			this._handleHash();
		} else if (this.options.openFirst && this.details.length > 0) {
			this.details[0].open = true;
		}

		this._setupAnimations();
		this._listenForHashChanges();
		if (this.container) {
			this.container.details = this;
		}
	}

	_localOptions(container) {
		if (!container.dataset) return {};
		return Object.fromEntries(
			Object.entries(container.dataset).map(([key, value]) => {
				value = value.trim();
				if (value === "true" || value === "false") {
					return [key, value === "true"];
				}
				if (/^\d+$/.test(value)) {
					return [key, Number(value)];
				}
				return [key, value];
			})
		);
	}

	_listenForHashChanges() {
		window.addEventListener('click', e => {
			const hashtest = e.target.tagName.toLowerCase() === 'a' &&
				e.target.getAttribute('href') &&
				e.target.getAttribute('href').startsWith('#');
			if (!hashtest) return;
			const targetId = e.target.getAttribute('href').slice(1);
			if (!this.details.find(d => d.id === targetId)) return;
			e.preventDefault();
			history.pushState(null, '', `#${targetId}`);
			this._handleHash();
		});
	}

	_handleHash() {
		const hash = location.hash.slice(1);
		if (!hash) return;
		const detail = this.details.find(d => d.id === hash);
		if (!detail) return;
		if (detail.open) {
			this._ensureVisible(detail);
		} else {
			this._openDetail(detail);
		}
		history.replaceState(null, '', location.pathname + location.search);
	}

	_hasDetailHash() {
		const hash = location.hash.slice(1);
		if (!hash) return false;
		return this.details.some(d => d.id === hash);
	}

	isDomNode(node) {
		return node && typeof node === "object" && "nodeType" in node && node.nodeType === 1;
	}

	findDetails(selector) {
		let details = [];
		if (typeof selector === "string") {
			details = Array.from(document.querySelectorAll(selector));
		} else if (Array.isArray(selector)) {
			details = selector;
		} else if (this.isDomNode(selector)) {
			const detailsInContainer = selector.querySelectorAll('details.cms-accordion');
			details = Array.from(detailsInContainer);
		} else {
			console.warn("Invalid Details selector");
		}
		return details;
	}

	_ensureVisible(detail) {
		setTimeout(() => {
			const rect = detail.getBoundingClientRect();
			const offset = this.options.scrollOffset;
			if (rect.top < offset || rect.top > window.innerHeight - offset) {
				detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			if (detail._summary) {
				detail._summary.focus({ preventScroll: true });
			}
		}, this.options.speed + 50);
	}

	_setupAnimations() {
		this.details.forEach(detail => {
			detail._animation = null;
			detail._isClosing = false;
			detail._isExpanding = false;
			detail._summary = detail.querySelector('summary');
			detail._content = detail.querySelector('.content');
		});

		// Use event delegation on container if available, otherwise on each detail
		if (this.container) {
			this.container.addEventListener('click', e => {
				if (e.target.tagName.toLowerCase() !== 'summary') return;
				const detail = e.target.closest('details.cms-accordion');
				if (detail && this.details.includes(detail)) {
					e.preventDefault();
					this._onSummaryClick(detail);
				}
			});
		} else {
			// Fallback: attach to each detail element
			this.details.forEach(detail => {
				detail.addEventListener('click', e => {
					if (e.target.tagName.toLowerCase() === 'summary') {
						e.preventDefault();
						this._onSummaryClick(detail);
					}
				});
			});
		}
	}

	_onSummaryClick(detail) {
		detail.style.overflow = 'hidden';
		if (detail._isClosing || !detail.open) {
			this._openDetail(detail);
		} else if (detail._isExpanding || detail.open) {
			this._shrinkDetail(detail);
		}
	}

	_openDetail(detail) {
		if (this.options.soloMode) {
			this.details.forEach(other => {
				if (other !== detail && other.open) {
					this._shrinkDetail(other);
				}
			});
		}
		detail._isExpanding = true;
		detail.style.height = `${detail.offsetHeight}px`;
		detail.open = true;
		window.requestAnimationFrame(() => this._expandDetail(detail));
	}

	_expandDetail(detail) {
		const endHeight = `${detail._summary.offsetHeight + detail._content.offsetHeight}px`;
		this._animateDetail(detail, endHeight, true, () => detail._isExpanding = false);
	}

	_shrinkDetail(detail) {
		detail._isClosing = true;
		const endHeight = `${detail._summary.offsetHeight}px`;
		this._animateDetail(detail, endHeight, false, () => detail._isClosing = false);
	}

	_animateDetail(detail, endHeight, open, oncancel) {
		if (detail._animation) detail._animation.cancel();

		const startHeight = `${detail.offsetHeight}px`;

		detail._animation = detail.animate(
			{ height: [startHeight, endHeight] },
			{
				duration: this.options.speed,
				easing: this.options.animation,
			}
		);
		detail._animation.onfinish = () => this._onAnimationFinish(detail, open);
		detail._animation.oncancel = oncancel;
	}

	_onAnimationFinish(detail, open) {
		detail.open = open;
		detail._animation = null;
		detail._isClosing = detail._isExpanding = false;
		detail.style.height = '';
		detail.style.overflow = '';
		if (open) this._ensureVisible(detail);
	}
}

window.Details = Details;