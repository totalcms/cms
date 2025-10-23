/**
 * ThemeSwitcher - Manages light/dark/auto theme switching for the dashboard
 * Stores preference in localStorage and applies CSS color-scheme property
 */
export default class ThemeSwitcher {
	static STORAGE_KEY = 'totalcms-theme';
	static THEMES = ['auto', 'light', 'dark'];

	/**
	 * Apply theme immediately on page load (before DOM ready)
	 * Call this as early as possible to prevent flash of wrong theme
	 */
	static applyEarly() {
		const saved = localStorage.getItem(ThemeSwitcher.STORAGE_KEY);
		const theme = ThemeSwitcher.THEMES.includes(saved) ? saved : 'auto';
		ThemeSwitcher.applyThemeToDocument(theme);
	}

	/**
	 * Static method to apply theme to document
	 */
	static applyThemeToDocument(theme) {
		const html = document.documentElement;

		// Remove existing theme classes
		html.classList.remove('theme-light', 'theme-dark', 'theme-auto');

		// Add new theme class
		html.classList.add(`theme-${theme}`);

		// Set CSS color-scheme property
		switch (theme) {
			case 'light':
				html.style.colorScheme = 'light';
				break;
			case 'dark':
				html.style.colorScheme = 'dark';
				break;
			case 'auto':
			default:
				html.style.colorScheme = 'light dark';
				break;
		}
	}

	constructor(container) {
		this.container = container;
		this.buttons = Array.from(container.querySelectorAll('[data-theme]'));

		// Load saved theme or default to 'auto'
		this.currentTheme = this.loadTheme();

		// Theme is already applied via applyEarly(), just update buttons
		this.updateButtons();

		// Add event listeners
		this.buttons.forEach(button => {
			button.addEventListener('click', () => this.handleThemeChange(button.dataset.theme));
		});
	}

	/**
	 * Load theme from localStorage
	 */
	loadTheme() {
		const saved = localStorage.getItem(ThemeSwitcher.STORAGE_KEY);
		return ThemeSwitcher.THEMES.includes(saved) ? saved : 'auto';
	}

	/**
	 * Save theme to localStorage
	 */
	saveTheme(theme) {
		localStorage.setItem(ThemeSwitcher.STORAGE_KEY, theme);
	}

	/**
	 * Handle theme change from user interaction
	 */
	handleThemeChange(theme) {
		if (theme === this.currentTheme) return;

		this.currentTheme = theme;
		this.saveTheme(theme);
		this.applyTheme(theme);
		this.updateButtons();
	}

	/**
	 * Apply theme to the document (instance method)
	 */
	applyTheme(theme) {
		ThemeSwitcher.applyThemeToDocument(theme);
	}

	/**
	 * Update button states to reflect current theme
	 */
	updateButtons() {
		this.buttons.forEach(button => {
			const isActive = button.dataset.theme === this.currentTheme;
			button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
		});
	}
}
