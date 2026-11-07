<?php
declare(strict_types=1);

function media_require_login(string $next = 'media/studio'): void
{
    $auth = System\Engine\Registry::getInstance()->get('auth');
    if (!$auth->check()) {
        redirect_to('user/login', ['next' => $next]);
    }
}

function media_require_admin(): void
{
    media_require_login('media/admin');
}

function media_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'channel-' . bin2hex(random_bytes(3));
}

/** YouTube-style public video id (URL-safe). */
function media_normalize_video_id(mixed $id): string
{
    $id = trim((string) $id);
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{8,16}$/', $id)) {
        return '';
    }
    return $id;
}

/**
 * Generate a unique public video id via random_string().
 * Uses URL-safe extras only (-_).
 */
function media_new_video_id(callable $exists): string
{
    for ($i = 0; $i < 24; $i++) {
        $id = random_string(11, '-_');
        if (!$exists($id)) {
            return $id;
        }
    }
    // Extremely unlikely collision path.
    return random_string(14, '-_');
}

function media_format_views(int $n): string
{
    if ($n >= 1_000_000) {
        return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1_000) {
        return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.') . 'K';
    }
    return (string) $n;
}

function media_relative_time(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' min ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . 'h ago';
    }
    if ($diff < 604800) {
        $d = (int) floor($diff / 86400);
        return $d . 'd ago';
    }
    return date('M j, Y', $ts);
}

/** @return 'video'|'audio'|'image' */
function media_type_from_extension(string $ext): string
{
    $ext = strtolower($ext);
    $audio = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'];
    $image = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if (in_array($ext, $audio, true)) {
        return 'audio';
    }
    if (in_array($ext, $image, true)) {
        return 'image';
    }
    return 'video';
}

/**
 * Detect YouTube / Vimeo / direct media URLs.
 *
 * @return array{media_type:string,source_url:string,embed_id:?string,provider:?string,thumbnail:?string}|null
 */
function media_parse_external_url(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? $host;

    // YouTube
    if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com'], true)) {
        $id = null;
        if ($host === 'youtu.be') {
            $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
        } else {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $id = $q['v'] ?? null;
            if (!$id) {
                if (preg_match('#/(embed|shorts)/([A-Za-z0-9_-]{6,})#', $url, $m)) {
                    $id = $m[2];
                }
            }
        }
        $id = is_string($id) ? preg_replace('/[^A-Za-z0-9_-]/', '', $id) : null;
        if ($id === null || $id === '') {
            return null;
        }
        return [
            'media_type' => 'embed',
            'source_url' => 'https://www.youtube.com/watch?v=' . $id,
            'embed_id'   => $id,
            'provider'   => 'youtube',
            'thumbnail'  => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
        ];
    }

    // Vimeo
    if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
        if (preg_match('#(?:vimeo\.com)/(?:video/)?(\d+)#', $url, $m)) {
            return [
                'media_type' => 'embed',
                'source_url' => 'https://vimeo.com/' . $m[1],
                'embed_id'   => $m[1],
                'provider'   => 'vimeo',
                'thumbnail'  => null,
            ];
        }
        return null;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'webm', 'ogg', 'mp3', 'wav', 'm4a', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return [
            'media_type' => media_type_from_extension($ext),
            'source_url' => $url,
            'embed_id'   => null,
            'provider'   => 'direct',
            'thumbnail'  => null,
        ];
    }

    return null;
}

function media_embed_html(array $video): string
{
    $provider = null;
    $id = null;
    $source = (string) ($video['source_url'] ?? '');
    $parsed = $source !== '' ? media_parse_external_url($source) : null;
    if ($parsed !== null) {
        $provider = $parsed['provider'];
        $id = $parsed['embed_id'];
    }

    if ($provider === 'youtube' && $id) {
        $safe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        return '<iframe class="media-embed" src="https://www.youtube-nocookie.com/embed/'
            . $safe . '?rel=0" title="YouTube video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
    }

    if ($provider === 'vimeo' && $id) {
        $safe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        return '<iframe class="media-embed" src="https://player.vimeo.com/video/'
            . $safe . '" title="Vimeo video" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
    }

    return '';
}

function media_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = '';
    try {
        $base = rtrim((string) registry('request')->basePath(), '/');
    } catch (Throwable) {
        $base = '';
    }

    return ($base !== '' ? $base : '') . '/' . $path;
}

/** @return list<string> */
function media_allowed_extensions(): array
{
    return [
        'mp4', 'webm', 'mkv', 'avi', 'mov', 'm4v',
        'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
    ];
}
