import TotalField from './totalfield';

//-----------------------------------------------
// Total CMS Code Editor Field
//-----------------------------------------------
export default class Code extends TotalField {

    constructor(container, settings) {
        super(container, settings);

        this.editor = null;
        this.localStorageKey = `totalcms-code-${this.property}`;
        this.autoSaveEnabled = false;

        this.initializeCodeEditor();
    }

    initializeCodeEditor() {
        if (!window.TotalCMSCodeMirror) {
            console.warn('TotalCMSCodeMirror not loaded yet, retrying...');
            setTimeout(() => this.initializeCodeEditor(), 100);
            return;
        }

        const mode = this.input.dataset.mode || 'twig';
        const editorOptions = this.input.dataset.editorOptions ?
            JSON.parse(this.input.dataset.editorOptions) : {};

        const config = {
            value          : this.input.value || '',
            indentUnit     : editorOptions.indentUnit || 2,
            tabSize        : editorOptions.tabSize || 2,
            lineNumbers    : editorOptions.lineNumbers !== false,
            lineWrapping   : editorOptions.lineWrapping !== false,
            foldGutter     : editorOptions.foldGutter !== false,
            matchBrackets  : editorOptions.matchBrackets !== false,
            autoCloseBrackets : editorOptions.autoCloseTags !== false,
            ...editorOptions
        };

        // Hide the textarea and create a container for CM6
        this.input.style.display = 'none';
        this.editorContainer = document.createElement('div');
        this.editorContainer.className = 'totalform-code-editor-container';
        this.input.parentNode.insertBefore(this.editorContainer, this.input.nextSibling);

        // Create the appropriate editor based on mode
        if (mode === 'twig') {
            this.editor = window.TotalCMSCodeMirror.createTwigEditor(this.editorContainer, config);
        } else if (mode === 'html' || mode === 'htmlmixed') {
            this.editor = window.TotalCMSCodeMirror.createHtmlEditor(this.editorContainer, config);
        } else if (mode === 'css') {
            this.editor = window.TotalCMSCodeMirror.createCssEditor(this.editorContainer, config);
        } else if (mode === 'javascript' || mode === 'js') {
            this.editor = window.TotalCMSCodeMirror.createJsEditor(this.editorContainer, config);
        } else {
            this.editor = window.TotalCMSCodeMirror.createEditor(this.editorContainer, {
                mode: mode,
                ...config
            });
        }

        // Add custom CSS classes for styling
        this.editorContainer.classList.add('totalform-code-editor');
        this.editorContainer.classList.add(`totalform-code-editor-${mode}`);

        // Set up auto-resize, auto-save, form submission, and fullscreen
        this.setupAutoResize();
        this.setupAutoSave();
        this.setupFormSubmission();
        this.setupFullscreenButton();

        // Refresh after initialization
        setTimeout(() => this.editor.refresh(), 150);
    }

    setupFullscreenButton() {
        if (!this.editor) return;

        const editorOptions = this.input.dataset.editorOptions ?
            JSON.parse(this.input.dataset.editorOptions) : {};
        if (editorOptions.fullscreen === false) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-fullscreen-btn';
        btn.title = 'Fullscreen';
        btn.setAttribute('aria-label', 'Toggle fullscreen');
        btn.innerHTML = '<span class="code-fullscreen-icon"></span>';
        this.editorContainer.appendChild(btn);

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleFullscreen();
        });
    }

    toggleFullscreen() {
        this.editorContainer.classList.toggle('code-fullscreen');
        const isFullscreen = this.editorContainer.classList.contains('code-fullscreen');

        if (isFullscreen) {
            this._escHandler = (e) => {
                if (e.key === 'Escape') {
                    this.editorContainer.classList.remove('code-fullscreen');
                    document.removeEventListener('keydown', this._escHandler);
                    this._escHandler = null;
                    this.editor.refresh();
                }
            };
            document.addEventListener('keydown', this._escHandler);
        } else if (this._escHandler) {
            document.removeEventListener('keydown', this._escHandler);
            this._escHandler = null;
        }

        this.editor.refresh();
    }

    setupAutoResize() {
        if (!this.editor) return;

        // Calculate height from rows attribute
        const rows = parseInt(this.input.getAttribute('rows')) || 10;
        const lineHeight = this.editor.defaultTextHeight() || 20;
        const rowsHeight = rows * lineHeight + 20;

        const minHeight = parseInt(this.input.dataset.minHeight) || rowsHeight;
        const maxHeight = parseInt(this.input.dataset.maxHeight) || 0;

        const wrapper = this.editorContainer;
        wrapper.style.minHeight = minHeight + 'px';

        // Track whether the user has manually dragged to resize
        let userResized = false;
        let lastAutoHeight = 0;

        const resizeObserver = new ResizeObserver(() => {
            const currentHeight = wrapper.offsetHeight;
            if (lastAutoHeight > 0 && Math.abs(currentHeight - lastAutoHeight) > 2) {
                userResized = true;
                this.editor.refresh();
            }
        });
        resizeObserver.observe(wrapper);

        const setHeight = () => {
            if (userResized && wrapper.offsetHeight > (maxHeight || Infinity)) {
                return;
            }

            const scrollInfo = this.editor.getScrollInfo();
            const contentHeight = scrollInfo.height + 20;
            let targetHeight = Math.max(contentHeight, minHeight);
            if (maxHeight > 0) {
                targetHeight = Math.min(targetHeight, maxHeight);
            }

            if (userResized && wrapper.offsetHeight > targetHeight) {
                return;
            }

            userResized = false;
            lastAutoHeight = targetHeight;
            wrapper.style.height = targetHeight + 'px';
        };

        setTimeout(() => setHeight(), 100);

        let setHeightTimer = null;
        this.editor.on('changes', () => {
            clearTimeout(setHeightTimer);
            setHeightTimer = setTimeout(setHeight, 100);
        });
    }

    setupAutoSave() {
        if (!this.editor || !this.localStorageKey) return;

        const isEditing = this.form && (this.form.isEditMode() || this.form.isTemplateEditMode());
        if (isEditing) {
            if (window.localStorage) {
                window.localStorage.removeItem(this.localStorageKey);
            }
            this.autoSaveEnabled = false;
            return;
        }

        this.autoSaveEnabled = true;

        const savedContent = window.TotalCMSCodeMirror?.loadFromStorage(this.localStorageKey);
        if (savedContent && !this.input.value) {
            this.editor.setValue(savedContent);
        } else if (!this.input.value || this.input.value.trim() === '') {
            this.editor.setValue('\n\n\n\n\n\n\n\n\n');
            this.editor.setCursor(0, 0);
        }

        this.editor.on('change', () => {
            if (!this.form || (!this.form.isEditMode() && !this.form.isTemplateEditMode())) {
                if (window.TotalCMSCodeMirror?.saveToStorage) {
                    window.TotalCMSCodeMirror.saveToStorage(this.localStorageKey, this.editor.getValue().replace(/\n+$/, ''));
                }
            }
        });
    }

    setupFormSubmission() {
        if (!this.editor) return;

        const form = this.container.closest('form');
        if (form) {
            form.addEventListener('submit', () => {
                this.input.value = this.getValue();
            });
        }

        this.editor.on('change', () => {
            this.input.value = this.getValue();
            if (this.input.value.trim()) {
                this.input.removeAttribute('required');
            } else if (this.input.hasAttribute('data-required')) {
                this.input.setAttribute('required', '');
            }

            if (!this.autoSaveEnabled) {
                this.changed();
            }
        });

        if (this.input.hasAttribute('required')) {
            this.input.setAttribute('data-required', 'true');
        }
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
        if (this.editor) {
            this.input.value = this.getValue();
        }
        return super.validate();
    }
}
