<?php
declare(strict_types=1);

namespace System\Library;

/**
 * Strip ads, tracking markup, and wrapper divs from pasted article HTML.
 */
final class HtmlCleaner
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'a', 'img', 'h1', 'h2', 'h3', 'h4',
        'ul', 'ol', 'li', 'blockquote',
        'figure', 'figcaption',
    ];

    private const UNWRAP = [
        'div', 'span', 'section', 'article', 'header', 'footer',
        'main', 'aside', 'font', 'center', 'picture',
    ];

    private const DROP = [
        'script', 'style', 'noscript', 'iframe', 'object', 'embed',
        'form', 'button', 'input', 'select', 'textarea', 'label',
        'svg', 'canvas', 'video', 'audio', 'source', 'link', 'meta',
        'template', 'dialog',
    ];

    private const AD_PATTERN = '/(?:^|[\s_-])(?:ads?(?:unit|slot|wrapper|banner)?|advert(?:isement|orial)?|adsbygoogle|sponsor(?:ed)?|promo(?:ted)?|taboola|outbrain|mgid|revcontent|sharethis|addthis|newsletter|cookie(?:consent)?|related[-_]?posts|recommended|popup|modal)(?:$|[\s_-])|digital[-_]?wrapper|xsouk/i';

    public static function clean(string $html): string
    {
        return self::process($html, true);
    }

    /**
     * Drop scripts, iframes, and event handlers without rewriting layout.
     */
    public static function sanitize(string $html): string
    {
        return self::process($html, false);
    }

    private static function process(string $html, bool $aggressive): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
            . '<div id="__sf_clean">' . $html . '</div></body></html>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('__sf_clean');
        if ($root === null) {
            return '';
        }

        self::walk($root, $aggressive);
        if ($aggressive) {
            self::wrapOrphans($root);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        $out = preg_replace('/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/iu', '', $out) ?? $out;
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;

        return trim($out);
    }

    private static function walk(\DOMNode $node, bool $aggressive): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP, true) || ($aggressive && self::looksLikeAd($child))) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            self::walk($child, $aggressive);

            if ($child->parentNode === null) {
                continue;
            }

            if ($aggressive && (in_array($tag, self::UNWRAP, true) || !in_array($tag, self::ALLOWED, true))) {
                self::unwrap($child);
                continue;
            }

            if ($aggressive) {
                self::cleanAttributes($child);
            } else {
                self::stripDangerousAttributes($child);
            }

            if ($tag === 'img' && !self::safeSrc($child->getAttribute('src'))) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if ($href === '' || preg_match('#^\s*javascript:#i', $href) === 1) {
                    self::unwrap($child);
                }
            }
        }
    }

    private static function looksLikeAd(\DOMElement $el): bool
    {
        $bits = [$el->getAttribute('id'), $el->getAttribute('class')];
        foreach ($el->attributes ?? [] as $attr) {
            $bits[] = $attr->name;
            $bits[] = $attr->value;
        }

        return preg_match(self::AD_PATTERN, implode(' ', $bits)) === 1;
    }

    private static function wrapOrphans(\DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            if (!$child instanceof \DOMText) {
                continue;
            }
            $text = trim(str_replace("\xC2\xA0", ' ', $child->textContent));
            if ($text === '') {
                $child->parentNode?->removeChild($child);
                continue;
            }
            $p = $doc->createElement('p');
            $root->insertBefore($p, $child);
            $p->appendChild($child);
        }
    }

    private static function unwrap(\DOMNode $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private static function stripDangerousAttributes(\DOMElement $el): void
    {
        $names = [];
        foreach ($el->attributes ?? [] as $attr) {
            $names[] = $attr->name;
        }
        foreach ($names as $name) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'on') || $lower === 'srcdoc') {
                $el->removeAttribute($name);
            }
        }
    }

    private static function cleanAttributes(\DOMElement $el): void
    {
        $tag = strtolower($el->tagName);
        $keep = match ($tag) {
            'a' => ['href'],
            'img' => ['src', 'alt'],
            default => [],
        };

        $names = [];
        foreach ($el->attributes ?? [] as $attr) {
            $names[] = $attr->name;
        }
        foreach ($names as $name) {
            if (!in_array(strtolower($name), $keep, true)) {
                $el->removeAttribute($name);
            }
        }
    }

    private static function safeSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        if (preg_match('#^(javascript|data):#i', $src) === 1) {
            return false;
        }
        return true;
    }
}
