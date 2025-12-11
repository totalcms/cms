/**
 * Documentation Syntax Highlighting
 * Uses highlight.js for code blocks in documentation pages
 */

import hljs from 'highlight.js/lib/core';

// Import only the languages we need
import twig from 'highlight.js/lib/languages/twig';
import json from 'highlight.js/lib/languages/json';
import javascript from 'highlight.js/lib/languages/javascript';
import bash from 'highlight.js/lib/languages/bash';
import xml from 'highlight.js/lib/languages/xml'; // HTML is part of xml
import php from 'highlight.js/lib/languages/php';
import css from 'highlight.js/lib/languages/css';
import apache from 'highlight.js/lib/languages/apache';

// Register languages
hljs.registerLanguage('twig', twig);
hljs.registerLanguage('json', json);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('js', javascript);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('shell', bash);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('php', php);
hljs.registerLanguage('css', css);
hljs.registerLanguage('apache', apache);

// Also register 'http' as plain text since highlight.js doesn't have a dedicated http mode
hljs.registerLanguage('http', () => ({ contains: [] }));

// Initialize highlighting
hljs.highlightAll();

// Export for potential manual use
export { hljs };
