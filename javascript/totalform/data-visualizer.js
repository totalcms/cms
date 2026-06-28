//-----------------------------------------------
// Collection Visualizer — lazy Mermaid ERD render with pan/zoom
//
// Mermaid + svg-pan-zoom are large, so they are dynamically imported (separate
// esbuild chunks) only when this page is on screen — they never weigh down the
// main admin bundle. The diagram is rendered at natural size inside a fixed
// viewport and made pan/zoomable, since a wide ER diagram is unreadable when
// squeezed to the page width. svg-pan-zoom's built-in control icons are
// disabled in favour of our own design-system buttons (see .visualizer-zoom).
//-----------------------------------------------
export default class DataVisualizer {

	constructor(container) {
		this.container = container;
		this.graphEl   = container.querySelector('.mermaid');
		this.toolbar   = container.querySelector('.visualizer-zoom');
		this.instance  = null;
		this.svgEl     = null;

		this.initControls();

		if (this.graphEl) this.render();
	}

	prefersDark() {
		const html = document.documentElement;
		if (html.classList.contains('theme-dark')) return true;
		if (html.classList.contains('theme-light')) return false;
		return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
	}

	// Fill from the canvas's top down to the bottom of the viewport. CSS can't
	// know the height of the admin chrome above it, so measure and set it here.
	// Skipped while fullscreen (the element fills the screen on its own).
	sizeCanvas() {
		if (document.fullscreenElement === this.container) {
			this.container.style.height = '';
			return;
		}
		const gap = 16;
		const top = this.container.getBoundingClientRect().top;
		const height = Math.max(window.innerHeight - top - gap, 320);
		this.container.style.height = `${height}px`;
	}

	async render() {
		const definition = this.graphEl.textContent;
		this.sizeCanvas();

		try {
			const { default: mermaid } = await import('mermaid');
			mermaid.initialize({
				startOnLoad : false,
				theme       : this.prefersDark() ? 'dark' : 'default',
				// Render at natural size; the viewport + pan/zoom handle large graphs.
				er          : { useMaxWidth: false },
			});

			const { svg, bindFunctions } = await mermaid.render('collection-visualizer-svg', definition);

			const host = document.createElement('div');
			host.className = 'visualizer-svg';
			host.innerHTML = svg;
			this.graphEl.replaceWith(host);
			bindFunctions?.(host);

			await this.enablePanZoom(host.querySelector('svg'));
			this.styleEdges();
			this.toolbar?.removeAttribute('hidden');
			// Fade the finished diagram in (next frame so the transition runs).
			requestAnimationFrame(() => host.classList.add('is-ready'));
		} catch (err) {
			console.error('Collection Visualizer render failed:', err);
			this.graphEl.insertAdjacentHTML(
				'afterend',
				'<p class="error">Could not render the diagram — see the browser console for details.</p>',
			);
		}
	}

	// Force the SVG to an explicit pixel size matching the viewport. A bare
	// `height: 100%` collapses an inline SVG to its CSS default of 150px unless
	// every ancestor has a definite height, so we set pixels directly.
	fitSvg() {
		if (!this.svgEl) return;
		this.svgEl.style.width    = `${this.container.clientWidth}px`;
		this.svgEl.style.height   = `${this.container.clientHeight}px`;
		this.svgEl.style.maxWidth = 'none';
		this.svgEl.removeAttribute('width');
		this.svgEl.removeAttribute('height');
	}

	// Resize everything to the current viewport and re-centre the diagram.
	refit() {
		this.sizeCanvas();
		this.fitSvg();
		this.instance?.resize();
		this.instance?.fit();
		this.instance?.center();
	}

	async enablePanZoom(svgEl) {
		if (!svgEl) return;

		this.svgEl = svgEl;
		this.fitSvg();

		const { default: svgPanZoom } = await import('svg-pan-zoom');
		this.instance = svgPanZoom(svgEl, {
			controlIconsEnabled : false, // we render our own controls
			fit                 : true,
			center              : true,
			minZoom             : 0.1,
			maxZoom             : 20,
			zoomScaleSensitivity: 0.3,
		});

		// Re-fit on window resize (debounced to a frame).
		let raf = 0;
		window.addEventListener('resize', () => {
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(() => this.refit());
		});
	}

	// Mermaid's erDiagram renders every relationship with one theme colour, so
	// colour + dash each line by edge type to match the legend. The PHP renderer
	// emits the edge types in the same order as the relationship lines, and
	// Mermaid draws `.relationshipLine` paths in that declaration order. Keep
	// these styles in sync with the .visualizer-legend rules.
	styleEdges() {
		if (!this.svgEl) return;

		let types = [];
		try { types = JSON.parse(this.container.dataset.edgeTypes || '[]'); } catch { /* ignore */ }
		if (types.length === 0) return;

		const root  = getComputedStyle(document.documentElement);
		const oklch = (name) => `oklch(${root.getPropertyValue(name).trim()})`;
		const style = {
			relational:  { color: oklch('--totalform-accent'),   dash: '' },
			composition: { color: oklch('--totalform-accent'),   dash: '6 4' },
			inheritance: { color: oklch('--totalform-darkgray'), dash: '2 4' },
			dataview:    { color: oklch('--totalform-success'),  dash: '6 4' },
		};

		const paths = this.svgEl.querySelectorAll('path.relationshipLine');
		paths.forEach((path, i) => {
			// erDiagram always draws cardinality end markers (the `||` bars); we
			// don't model real cardinality, so strip them — type is shown by
			// colour + dash. Inline style overrides Mermaid's marker attributes.
			path.style.markerStart = 'none';
			path.style.markerEnd   = 'none';

			const s = style[types[i]];
			if (!s) return;
			path.style.stroke = s.color;
			path.style.strokeDasharray = s.dash;
			path.style.strokeWidth = '2px'; // thicker for legibility (esp. dark mode)
		});
	}

	initControls() {
		// Delegated so it works regardless of when the diagram finishes rendering.
		this.toolbar?.addEventListener('click', (e) => {
			const action = e.target.closest('[data-zoom]')?.dataset.zoom;
			if (!action) return;

			switch (action) {
				case 'in':         this.instance?.zoomIn(); break;
				case 'out':        this.instance?.zoomOut(); break;
				case 'reset':      this.refit(); break;
				case 'fullscreen': this.toggleFullscreen(); break;
			}
		});

		// Re-fit after entering/leaving fullscreen (next frame, once the element
		// has resized to/from the screen).
		document.addEventListener('fullscreenchange', () => {
			this.container.classList.toggle('is-fullscreen', document.fullscreenElement === this.container);
			requestAnimationFrame(() => this.refit());
		});
	}

	toggleFullscreen() {
		if (document.fullscreenElement === this.container) {
			document.exitFullscreen?.();
		} else {
			this.container.requestFullscreen?.();
		}
	}
}
