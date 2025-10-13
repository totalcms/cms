import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Code Editor Field
//-----------------------------------------------
export default class Code extends TotalField {

    constructor(container, options) {
        super(container, options);

        this.editor = null;
        this.localStorageKey = `totalcms-code-${this.property}`;
        this.autoSaveEnabled = false; // Track if auto-save is enabled

        // Initialize CodeMirror when available
        this.initializeCodeEditor();
    }

    initializeCodeEditor() {
        // Wait for TotalCMSCodeMirror to be available
        if (!window.TotalCMSCodeMirror) {
            console.warn('TotalCMSCodeMirror not loaded yet, retrying...');
            setTimeout(() => this.initializeCodeEditor(), 100);
            return;
        }

        const mode = this.input.dataset.mode || 'twig';
        const theme = this.input.dataset.theme || 'elegant';
        const editorOptions = this.input.dataset.editorOptions ?
            JSON.parse(this.input.dataset.editorOptions) : {};

        // Default editor configuration
        const config = {
            theme          : theme,
            viewportMargin : Infinity,                                              // Auto-expand
            indentUnit     : editorOptions.indentUnit || 2,
            tabSize        : editorOptions.tabSize || 2,
            lineNumbers    : editorOptions.lineNumbers !== false,
            lineWrapping   : editorOptions.lineWrapping !== false,
            foldGutter     : editorOptions.foldGutter !== false,
            matchBrackets  : editorOptions.matchBrackets !== false,
            autoCloseTags  : editorOptions.autoCloseTags !== false,
            gutters        : ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
            ...editorOptions
        };

        // Create the appropriate editor based on mode
        if (mode === 'twig') {
            this.editor = window.TotalCMSCodeMirror.createTwigEditor(this.input, config);
        } else if (mode === 'html' || mode === 'htmlmixed') {
            this.editor = window.TotalCMSCodeMirror.createHtmlEditor(this.input, config);
        } else if (mode === 'css') {
            this.editor = window.TotalCMSCodeMirror.createCssEditor(this.input, config);
        } else if (mode === 'javascript' || mode === 'js') {
            this.editor = window.TotalCMSCodeMirror.createJsEditor(this.input, config);
        } else {
            // Fallback to generic editor
            this.editor = window.CodeMirror.fromTextArea(this.input, {
                mode : mode,
                ...config
            });
        }

        // Add custom CSS class for styling
        this.editor.getWrapperElement().classList.add('totalform-code-editor');
        this.editor.getWrapperElement().classList.add(`totalform-code-editor-${mode}`);

        // Set up auto-resize functionality
        this.setupAutoResize();

        // Set up auto-save (localStorage persistence)
        this.setupAutoSave();

        // Set up form submission handling
        this.setupFormSubmission();

        // Force a refresh after initialization to ensure proper gutter calculations
        setTimeout(() => {
            this.editor.refresh();
            // Force gutter width to prevent content overlap
            this.forceGutterWidth();
        }, 150);
    }

    setupAutoResize() {
        if (!this.editor) return;

        const resizeEditor = () => {
            // Get the actual scroll height which accounts for wrapped lines
            const scrollInfo = this.editor.getScrollInfo();
            const contentHeight = scrollInfo.height;

            // Use content height but enforce minimum height
            const minHeight = 250;
            const targetHeight = Math.max(contentHeight + 20, minHeight); // Add padding

            this.editor.setSize(null, targetHeight);
        };

        // Initial resize after a short delay
        setTimeout(() => {
            resizeEditor();
        }, 100);

        // Resize on content changes
        this.editor.on('changes', () => {
            setTimeout(() => {
                resizeEditor();
                this.forceGutterWidth();
            }, 0);
        });

        // Also resize when the editor is refreshed (handles wrapping changes)
        this.editor.on('refresh', () => {
            setTimeout(() => {
                resizeEditor();
            }, 0);
        });
    }

    setupAutoSave() {
        if (!this.editor || !this.localStorageKey) return;

        // Check if form is in edit mode using TotalField's form reference
        if (this.form && this.form.isEditMode()) {
            // Clear any existing storage data for edit mode
            if (window.TotalCMSCodeMirror?.clearStorage) {
                window.TotalCMSCodeMirror.clearStorage(this.localStorageKey);
            } else if (window.localStorage) {
                window.localStorage.removeItem(this.localStorageKey);
            }
            // Don't set up auto-save in edit mode
            this.autoSaveEnabled = false;
            return;
        }

        // Enable auto-save for create mode
        this.autoSaveEnabled = true;

        // Only load saved content in create mode
        const savedContent = window.TotalCMSCodeMirror?.loadFromStorage(this.localStorageKey);
        if (savedContent && !this.input.value) {
            this.editor.setValue(savedContent);
        } else if (!this.input.value || this.input.value.trim() === '') {
            // If empty, add some empty lines to show line numbers
            this.editor.setValue('\n\n\n\n\n\n\n\n\n');
            this.editor.setCursor(0, 0);
        }

        // Save content to localStorage on changes (only in create mode)
        this.editor.on('change', () => {
            // Double-check we're still not in edit mode before saving
            if (!this.form || !this.form.isEditMode()) {
                if (window.TotalCMSCodeMirror?.saveToStorage) {
                    window.TotalCMSCodeMirror.saveToStorage(this.localStorageKey, this.editor.getValue().replace(/\n+$/, ''));
                }
            }
        });
    }

    setupFormSubmission() {
        if (!this.editor) return;

        // Find the closest form
        const form = this.container.closest('form');
        if (form) {
            form.addEventListener('submit', (e) => {
                // Update the textarea value before form submission with trimmed content
                this.input.value = this.getValue();
            });
        }

        // Also update on any change for real-time updates
        this.editor.on('change', () => {
            this.input.value = this.getValue();
            // Remove required attribute if value is not empty to prevent validation issues
            if (this.input.value.trim()) {
                this.input.removeAttribute('required');
            } else if (this.input.hasAttribute('data-required')) {
                this.input.setAttribute('required', '');
            }

            // Only trigger change event if auto-save is not enabled
            // This prevents "unsaved changes" warnings when auto-save is active
            if (!this.autoSaveEnabled) {
                this.changed(); // Trigger TotalField change event
            }
        });

        // Store the original required state
        if (this.input.hasAttribute('required')) {
            this.input.setAttribute('data-required', 'true');
        }
    }

    forceGutterWidth() {
        if (!this.editor) return;

        // Force proper gutter widths to prevent content overlap
        const wrapper = this.editor.getWrapperElement();
        const gutters = wrapper.querySelector('.CodeMirror-gutters');
        const lineNumbers = wrapper.querySelector('.CodeMirror-linenumber');
        const foldGutter = wrapper.querySelector('.CodeMirror-foldgutter');

        if (gutters) {
            gutters.style.width = '56px';
        }
        if (lineNumbers) {
            lineNumbers.style.width = '40px';
            lineNumbers.style.minWidth = '40px';
        }
        if (foldGutter) {
            foldGutter.style.width = '16px';
        }

        // Force a refresh to apply the changes
        setTimeout(() => {
            this.editor.refresh();
        }, 10);
    }

    setValue(value) {
        if (this.editor) {
            this.editor.setValue(value);
        } else {
            this.input.value = value;
        }
        this.changed();
    }

    getValue() {
        if (this.editor) {
            return this.editor.getValue().replace(/\n+$/, '');
        }
        return this.input.value.replace(/\n+$/, '');
    }

    focus() {
        if (this.editor) {
            this.editor.focus();
        } else {
            this.input.focus();
        }
    }

    refresh() {
        if (this.editor) {
            setTimeout(() => {
                this.editor.refresh();
            }, 0);
        }
    }

    validate() {
        // Ensure the textarea has the latest value from CodeMirror with trimmed content
        if (this.editor) {
            this.input.value = this.getValue();
        }

        // Call parent validate method
        return super.validate();
    }
}