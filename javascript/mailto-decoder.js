/**
 * Mailto Decoder
 * Converts obfuscated email spans into functional mailto links
 * This prevents email addresses from being visible in raw source code
 */
(function() {
    'use strict';

    function decodeMailto() {
        // Find all obfuscated mailto elements
        const obfuscatedElements = document.querySelectorAll('.mailto-obfuscated');
        
        obfuscatedElements.forEach(element => {
            // Skip if already processed
            if (element.dataset.processed === 'true') return;
            
            // Extract encoded email parts
            const user = element.dataset.user;
            const domain = element.dataset.domain;
            
            if (!user || !domain) return;
            
            // Decode email parts
            const decodedUser = atob(user);
            const decodedDomain = atob(domain);
            const email = `${decodedUser}@${decodedDomain}`;
            
            // Build mailto URL
            let mailtoUrl = `mailto:${email}`;
            const params = [];
            
            // Add optional parameters
            if (element.dataset.subject) {
                params.push(`subject=${encodeURIComponent(atob(element.dataset.subject))}`);
            }
            if (element.dataset.body) {
                params.push(`body=${encodeURIComponent(atob(element.dataset.body))}`);
            }
            if (element.dataset.cc) {
                params.push(`cc=${encodeURIComponent(atob(element.dataset.cc))}`);
            }
            if (element.dataset.bcc) {
                params.push(`bcc=${encodeURIComponent(atob(element.dataset.bcc))}`);
            }
            
            if (params.length > 0) {
                mailtoUrl += '?' + params.join('&');
            }
            
            // Create the actual link
            const link = document.createElement('a');
            link.href = mailtoUrl;
            link.className = 'mailto-link';
            link.title = element.title || 'Email';
            
            // Copy over the content (encoded email display)
            link.innerHTML = element.innerHTML;
            
            // Replace the span with the link
            element.parentNode.replaceChild(link, element);
            
            // Mark as processed
            link.dataset.processed = 'true';
        });
    }
    
    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', decodeMailto);
    } else {
        decodeMailto();
    }
    
    // Also run on dynamic content updates (for AJAX loaded content)
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            let shouldRun = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    shouldRun = true;
                }
            });
            if (shouldRun) {
                decodeMailto();
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();