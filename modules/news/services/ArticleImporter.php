<?php
declare(strict_types=1);

namespace Module\News\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\HtmlCleaner;

/**
 * Fetch a public article URL and extract title, body, image, and source.
 */
final class ArticleImporter
{
    private const MAX_BYTES = 1_500_000;
    private const TIMEOUT   = 10;

    /**
     * @return array{title:string,excerpt:string,body:string,image:string,source:string,source_url:string}
     */
    public function import(string $url): array
    {
        $url  = $this->normalizeUrl($url);
        $html = $this->fetch($url);

        return $this->parse($html, $url);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Paste an article URL first.');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1 && preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException('Enter a valid http or https URL.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid http or https URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Enter a valid http or https URL.');
        }
        if ($this->isBlockedHost($host)) {
            throw new InvalidArgumentException('That URL is not allowed.');
        }

        return $url;
    }

    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            return $this->fetchCurl($url);
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 0,
                'max_redirects'   => 0,
                'header'          => "Accept: text/html,application/xhtml+xml\r\nUser-Agent: soomaali.net news importer\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('Could not download that page.');
        }

        return $html;
    }

    private function fetchCurl(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not start the download.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE    => self::MAX_BYTES,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; soomaali.net news importer)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $html = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ip   = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);

        if ($ip !== '' && !$this->isPublicIp($ip)) {
            throw new InvalidArgumentException('That URL is not allowed.');
        }

        $finalHost = strtolower((string) (parse_url($final, PHP_URL_HOST) ?? ''));
        if ($finalHost !== '' && $this->isBlockedHost($finalHost)) {
            throw new InvalidArgumentException('That URL is not allowed.');
        }

        if (!is_string($html) || $html === '') {
            throw new RuntimeException($err !== '' ? $err : 'Could not download that page.');
        }
        if ($code < 200 || $code >= 400) {
            throw new RuntimeException('The site returned HTTP ' . $code . '.');
        }
        if ($type !== '' && !str_contains($type, 'html') && !str_contains($type, 'xml') && !str_contains($type, 'text/plain')) {
            throw new RuntimeException('That URL is not an HTML page.');
        }
        if (strlen($html) > self::MAX_BYTES) {
            $html = substr($html, 0, self::MAX_BYTES);
        }

        return $html;
    }

    /**
     * @return array{title:string,excerpt:string,body:string,image:string,source:string,source_url:string}
     */
    private function parse(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);

        $title = $this->meta($xpath, 'og:title')
            ?: $this->meta($xpath, 'twitter:title')
            ?: $this->firstText($xpath, '//h1')
            ?: $this->firstText($xpath, '//title');
        $title = $this->tidyTitle($title);

        $excerpt = $this->meta($xpath, 'og:description')
            ?: $this->meta($xpath, 'description')
            ?: $this->meta($xpath, 'twitter:description');
        $excerpt = trim(html_entity_decode(strip_tags($excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (function_exists('mb_substr')) {
            $excerpt = mb_substr($excerpt, 0, 500);
        } else {
            $excerpt = substr($excerpt, 0, 500);
        }

        $image = $this->meta($xpath, 'og:image')
            ?: $this->meta($xpath, 'twitter:image')
            ?: $this->meta($xpath, 'twitter:image:src');
        $image = $this->absoluteUrl($image, $url);

        $source = $this->meta($xpath, 'og:site_name');
        if ($source === '') {
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            $source = preg_replace('/^www\./', '', $host) ?? $host;
        }

        $canonical = $this->canonical($xpath) ?: $url;
        $canonical = $this->absoluteUrl($canonical, $url) ?: $url;

        $body = HtmlCleaner::clean($this->extractBody($dom, $xpath));
        if (trim(strip_tags($body)) === '') {
            throw new RuntimeException('Could not find article text on that page. Paste the body instead.');
        }

        if ($title === '') {
            $title = 'Untitled article';
        }

        return [
            'title'      => $title,
            'excerpt'    => $excerpt,
            'body'       => $body,
            'image'      => $image,
            'source'     => mb_substr(trim($source), 0, 160),
            'source_url' => mb_substr($canonical, 0, 500),
        ];
    }

    private function extractBody(\DOMDocument $dom, \DOMXPath $xpath): string
    {
        foreach (['//article', '//*[@itemprop="articleBody"]', '//main', '//*[@role="main"]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $html = $this->innerHtml($nodes->item(0));
                if (strlen(strip_tags($html)) > 80) {
                    return $html;
                }
            }
        }

        $best = '';
        $bestLen = 0;
        $blocks = $xpath->query('//div|//section');
        if ($blocks !== false) {
            foreach ($blocks as $block) {
                if (!$block instanceof \DOMElement) {
                    continue;
                }
                $html = $this->innerHtml($block);
                $len = strlen(trim(strip_tags($html)));
                $pCount = substr_count(strtolower($html), '<p');
                if ($pCount >= 2 && $len > $bestLen) {
                    $best = $html;
                    $bestLen = $len;
                }
            }
        }

        return $best !== '' ? $best : ($dom->saveHTML() ?: '');
    }

    private function innerHtml(?\DOMNode $node): string
    {
        if ($node === null || $node->ownerDocument === null) {
            return '';
        }
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function meta(\DOMXPath $xpath, string $name): string
    {
        $queries = [
            '//meta[@property="' . $name . '"]/@content',
            '//meta[@name="' . $name . '"]/@content',
        ];
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                return trim((string) $nodes->item(0)?->nodeValue);
            }
        }
        return '';
    }

    private function canonical(\DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//link[@rel="canonical"]/@href');
        if ($nodes !== false && $nodes->length > 0) {
            return trim((string) $nodes->item(0)?->nodeValue);
        }
        return $this->meta($xpath, 'og:url');
    }

    private function firstText(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return trim(html_entity_decode((string) $nodes->item(0)?->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function tidyTitle(string $title): string
    {
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/\s*[|\-–—]\s*[^|\-–—]+$/', '', $title) ?? $title;
        return trim($title);
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        if ($host === '') {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }
        return $scheme . '://' . $host . '/' . ltrim($url, '/');
    }

    private function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !$this->isPublicIp($host);
        }

        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return true;
            }
        }
        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
