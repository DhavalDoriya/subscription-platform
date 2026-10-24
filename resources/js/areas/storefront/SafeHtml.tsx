import DOMPurify from 'dompurify';
import { useMemo } from 'react';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'a'];

// Rich text is sanitised on the server before it is stored. This is the second layer, so a server bug or a row written
// some other way still cannot run script in a visitor's browser.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') node.setAttribute('rel', 'nofollow noopener noreferrer');
});

export function SafeHtml({ html, className }: { html: string; className?: string }) {
    const clean = useMemo(
        () => DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR: ['href', 'title', 'rel'], ALLOWED_URI_REGEXP: /^(?:https?:|mailto:)/i }),
        [html],
    );

    return <div className={className} dangerouslySetInnerHTML={{ __html: clean }} />;
}
