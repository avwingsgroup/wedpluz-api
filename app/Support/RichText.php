<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Server-side rich-text sanitiser (api-contracts.md §1.5): an allow-list of
 * tags; scripts, styles, event handlers and javascript: URLs are stripped on
 * write. Links are forced to rel="noopener noreferrer nofollow".
 */
final class RichText
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'blockquote', 'span'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base'];

    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (strip_tags($html) === $html) {
            return e($html, false) === $html ? $html : htmlspecialchars($html, ENT_NOQUOTES, 'UTF-8', false);
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_NOQUOTES, 'UTF-8');
        }
        self::clean($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);

                    continue;
                }
                if (! in_array($tag, self::ALLOWED, true)) {
                    self::clean($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                $href = $tag === 'a' ? trim((string) $child->getAttribute('href')) : null;
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->nodeName);
                }
                if ($tag === 'a') {
                    if ($href !== null && preg_match('#^(https?://|mailto:)#i', $href)) {
                        $child->setAttribute('href', $href);
                    }
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                    $child->setAttribute('target', '_blank');
                }
                self::clean($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
