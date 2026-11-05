<?php
declare(strict_types=1);
namespace System\Library;

use InvalidArgumentException;
use RuntimeException;

class MB
{
    private static bool $m = false;
    private static bool $initialized = false;

    /**
     * Initialize the mbstring layer. Idempotent and safe to call multiple
     * times. If this is never called, mb_* wrappers fall back to plain
     * string functions (byte-based) — see ensureM().
     */
    public static function Initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        self::ensureM();
        self::$initialized = true;
    }

    /**
     * Lazily detect mbstring availability so that callers never have to
     * remember Initialize(). Runs at most once.
     */
    private static function ensureM(): bool
    {
        if (self::$initialized) {
            return self::$m;
        }

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
            mb_http_output('UTF-8');
            if (function_exists('mb_regex_encoding')) {
                mb_regex_encoding('UTF-8');
            }
            self::$m = true;
        }

        self::$initialized = true;

        if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
            $GLOBALS['config']['mb'] = self::$m;
        }

        return self::$m;
    }

    // ---------------------------------------------------------------------
    // Core mb-aware wrappers
    // ---------------------------------------------------------------------

    public static function length(?string $string = null): int
    {
        return self::strlen($string ?? '');
    }

    public static function lower(?string $str = null): string
    {
        $str ??= '';
        return self::ensureM() ? mb_strtolower($str, 'UTF-8') : strtolower($str);
    }

    public static function upper(?string $str = null): string
    {
        $str ??= '';
        return self::ensureM() ? mb_strtoupper($str, 'UTF-8') : strtoupper($str);
    }

    public static function substr(?string $str = null, int $start = 0, ?int $length = null): string
    {
        $str ??= '';
        return self::ensureM()
            ? mb_substr($str, $start, $length, 'UTF-8')
            : substr($str, $start, $length);
    }

    public static function strlen(?string $str = null): int
    {
        $str ??= '';
        return self::ensureM() ? mb_strlen($str, 'UTF-8') : strlen($str);
    }

    public static function substr_count(?string $haystack = null, ?string $needle = null): int
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_substr_count($haystack, $needle, 'UTF-8')
            : substr_count($haystack, $needle);
    }

    public static function strstr(?string $haystack = null, ?string $needle = null, bool $beforeNeedle = false): string|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_strstr($haystack, $needle, $beforeNeedle, 'UTF-8')
            : strstr($haystack, $needle, $beforeNeedle);
    }

    public static function stristr(?string $haystack = null, ?string $needle = null, bool $beforeNeedle = false): string|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_stristr($haystack, $needle, $beforeNeedle, 'UTF-8')
            : stristr($haystack, $needle, $beforeNeedle);
    }

    public static function ucfirst(?string $str = null): string
    {
        $str ??= '';
        return self::ensureM() ? mb_ucfirst($str, 'UTF-8') : ucfirst($str);
    }

    public static function strrpos(?string $haystack = null, ?string $needle = null, int $offset = 0): int|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_strrpos($haystack, $needle, $offset, 'UTF-8')
            : strrpos($haystack, $needle, $offset);
    }

    public static function strripos(?string $haystack = null, ?string $needle = null, int $offset = 0): int|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_strripos($haystack, $needle, $offset, 'UTF-8')
            : strripos($haystack, $needle, $offset);
    }

    public static function strpos(?string $haystack = null, ?string $needle = null, int $offset = 0): int|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_strpos($haystack, $needle, $offset, 'UTF-8')
            : strpos($haystack, $needle, $offset);
    }

    public static function stripos(?string $haystack = null, ?string $needle = null, int $offset = 0): int|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_stripos($haystack, $needle, $offset, 'UTF-8')
            : stripos($haystack, $needle, $offset);
    }

    /**
     * Returns the portion of $haystack after the last occurrence of $needle
     * (or before, if $beforeNeedle is true).
     *
     * NOTE: the original passed "UTF-8" in the $beforeNeedle slot — this
     * version fixes that and makes the two branches behave identically.
     */
    public static function strrchr(?string $haystack = null, ?string $needle = null, bool $beforeNeedle = false): string|false
    {
        $haystack ??= '';
        $needle   ??= '';
        return self::ensureM()
            ? mb_strrchr($haystack, $needle, $beforeNeedle, 'UTF-8')
            : strrchr($haystack, $needle);
    }

    /**
     * Byte-cut that avoids splitting a multibyte sequence.
     *
     * NOTE: the original returned $str unchanged in the non-mb branch,
     * which is not a fallback — it silently ignores the cut.
     */
    public static function strcut(?string $str = null, int $start = 0, ?int $length = null): string
    {
        $str ??= '';
        return self::ensureM()
            ? mb_strcut($str, $start, $length, 'UTF-8')
            : substr($str, $start, $length);
    }

    public static function parse_str(?string $string = null, array &$result = []): bool
    {
        $string ??= '';
        return self::ensureM()
            ? mb_parse_str($string, $result)
            : parse_str($string, $result);
    }

    // ---------------------------------------------------------------------
    // Higher-level string utilities
    // ---------------------------------------------------------------------

    /**
     * First position of $needle in $string. Returns false if not found.
     *
     * NOTE: the original used strrpos(), which returns the *last* position
     * and made startsWith()/before()/after()/from() return wrong results.
     */
    public static function position(
        string $needle,
        ?string $string = null,
        bool $caseInsensitive = false
    ): int|false {
        if ($needle === '') {
            throw new InvalidArgumentException('The needle must not be empty');
        }

        if ($caseInsensitive) {
            return self::strpos(self::lower($string), self::lower($needle), 0);
        }

        return self::strpos($string, $needle, 0);
    }

    /**
     * @param string|string[] $type
     * @return string|string[]
     */
    public static function pool(string|array $type, bool $array = true): string|array
    {
        if (is_array($type)) {
            $pool = [];
            foreach ($type as $t) {
                $pool = array_merge($pool, self::pool($t));
            }
        } else {
            $pool = match (strtolower($type)) {
                'alphalower' => range('a', 'z'),
                'alphaupper' => range('A', 'Z'),
                'alpha'      => self::pool(['alphaLower', 'alphaUpper']),
                'num'        => array_map('strval', range(0, 9)),
                'alphanum'   => self::pool(['alpha', 'num']),
                'base32'     => array_merge(self::pool('alphaUpper'), array_map('strval', range(2, 7))),
                'base32hex'  => array_merge(array_map('strval', range(0, 9)), range('A', 'V')),
                default      => [],
            };
        }

        return $array ? $pool : implode('', $pool);
    }

    /**
     * Cryptographically secure random string from the given character pool.
     */
    public static function random(?int $length = null, string $type = 'alphaNum'): string|false
    {
        $length ??= random_int(5, 10);

        if ($length < 1) {
            return '';
        }

        $pool = self::pool($type, false);

        if ($pool === '' || $pool === []) {
            return false;
        }

        // Guard against pools that don't survive base64 (e.g. all non-ASCII).
        if (!preg_match('/^[\x21-\x7e]+$/', (string) $pool)) {
            throw new InvalidArgumentException(
                'Pool must be printable ASCII for random()'
            );
        }

        $regex = '/[^' . preg_quote((string) $pool, '/') . ']/';
        $result = '';
        $iterations = 0;

        while (self::strlen($result) < $length) {
            if (++$iterations > 1000) {
                throw new RuntimeException('random(): unable to collect enough entropy');
            }

            $missing = $length - self::strlen($result);
            $bytes   = random_bytes($missing);
            $allowed = preg_replace($regex, '', base64_encode($bytes)) ?? '';
            $result .= substr($allowed, 0, $missing);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Regex helpers
    // ---------------------------------------------------------------------

    public static function match(
        string $string,
        string $pattern,
        int $flags = 0,
        int $offset = 0
    ): ?array {
        $result = preg_match($pattern, $string, $matches, $flags, $offset);
        return ($result === 1) ? $matches : null;
    }

    public static function matches(
        string $string,
        string $pattern,
        int $flags = 0,
        int $offset = 0
    ): bool {
        return self::match($string, $pattern, $flags, $offset) !== null;
    }

    public static function matchAll(
        string $string,
        string $pattern,
        int $flags = 0,
        int $offset = 0
    ): ?array {
        $result = preg_match_all($pattern, $string, $matches, $flags, $offset);
        return ($result > 0) ? $matches : null;
    }

    // ---------------------------------------------------------------------
    // Case / format conversion
    // ---------------------------------------------------------------------

    public static function camel(?string $value = null): string
    {
        return lcfirst(self::studly($value));
    }

    public static function camelToKebab(?string $value = null): string
    {
        $value ??= '';
        return self::lower(
            preg_replace('!([a-z0-9])([A-Z])!', '$1-$2', $value) ?? $value
        );
    }

    public static function kebab(?string $value = null): string
    {
        return self::snake($value, '-');
    }

    public static function kebabToCamel(?string $value = null): string
    {
        $value ??= '';
        return lcfirst(preg_replace_callback(
            '/-(.)/',
            static fn(array $m): string => self::upper($m[1]),
            $value
        ) ?? $value);
    }

    /**
     * Convert a value to snake_case. Delimiter defaults to "_".
     *
     * NOTE: the original used ctype_lower(), which is byte-oriented and
     * not UTF-8 aware. This version uses a proper case test.
     */
    public static function snake(?string $value = null, string $delimiter = '_'): string
    {
        $value ??= '';

        if ($value === '') {
            return '';
        }

        // Already snake_case (letters/digits only, no uppercase)?
        if (preg_match('/^[a-z0-9\p{Ll}]+$/u', $value) === 1
            && !preg_match('/\p{Lu}/u', $value)
        ) {
            return $value;
        }

        $value = preg_replace('/\s+/u', '', ucwords($value)) ?? $value;
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value) ?? $value;

        return self::lower($value);
    }

    public static function studly(?string $value = null): string
    {
        $value ??= '';
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    public static function ucwords(?string $string = null): string
    {
        $string ??= '';
        return self::ensureM()
            ? mb_convert_case($string, MB_CASE_TITLE, 'UTF-8')
            : ucwords($string);
    }

    // ---------------------------------------------------------------------
    // Search / slicing
    // ---------------------------------------------------------------------

    public static function contains(
        string $needle,
        ?string $string = null,
        bool $caseInsensitive = false
    ): bool {
        if ($needle === '') {
            return true;
        }

        return $caseInsensitive
            ? self::stripos($string, $needle) !== false
            : self::strpos($string, $needle) !== false;
    }

    public static function startsWith(
        string $needle,
        ?string $string = null,
        bool $caseInsensitive = false
    ): bool {
        if ($needle === '') {
            return true;
        }

        return self::position($needle, $string, $caseInsensitive) === 0;
    }

    public static function before(
        string $needle,
        string $string,
        bool $caseInsensitive = false
    ): string {
        $position = self::position($needle, $string, $caseInsensitive);
        if ($position === false) {
            return '';
        }
        return self::substr($string, 0, $position);
    }

    public static function after(
        string $needle,
        string $string,
        bool $caseInsensitive = false
    ): string {
        $position = self::position($needle, $string, $caseInsensitive);
        if ($position === false) {
            return '';
        }
        return self::substr($string, $position + self::length($needle));
    }

    public static function from(
        string $needle,
        string $string,
        bool $caseInsensitive = false
    ): string {
        $position = self::position($needle, $string, $caseInsensitive);
        if ($position === false) {
            return '';
        }
        return self::substr($string, $position);
    }

    public static function split(
        string|array|null $string,
        string $separator = ',',
        int $length = 1
    ): array {
        if (is_array($string)) {
            return $string;
        }

        $string ??= '';
        $parts = explode($separator, $string);
        $out   = [];

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && self::length($p) >= $length) {
                $out[] = $p;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Numeric
    // ---------------------------------------------------------------------

    public static function float(string|int|float|null $value = null): string
    {
        $value ??= '';
        $value = (string) $value;

        if (stripos($value, 'e') !== false) {
            $value = rtrim(rtrim(sprintf('%.16f', (float) $value), '0'), '.');
        }

        $value   = str_replace(',', '.', $value);
        $decimal = strrchr($value, '.');
        $decimal = ($decimal === false) ? 0 : strlen($decimal) - 1;

        return number_format((float) $value, $decimal, '.', '');
    }

    public static function toBytes(string $size): int
    {
        $size = trim($size);
        if ($size === '') {
            return 0;
        }

        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]i?b?)?\s*$/i', $size, $m)) {
            return (int) $size;
        }

        $n = (float) $m[1];
        $unit = strtolower($m[2] ?? '');
        $factor = match (true) {
            str_starts_with($unit, 't') => 1024 ** 4,
            str_starts_with($unit, 'g') => 1024 ** 3,
            str_starts_with($unit, 'm') => 1024 ** 2,
            str_starts_with($unit, 'k') => 1024,
            default => 1,
        };

        return (int) round($n * $factor);
    }

    /**
     * Cast a string to the given type.
     */
    public static function toType(mixed $string, mixed $type): mixed
    {
        if (!is_string($type)) {
            $type = gettype($type);
        }

        return match ($type) {
            'array'           => (array) $string,
            'bool', 'boolean' => filter_var($string, FILTER_VALIDATE_BOOLEAN),
            'double', 'float' => (float) $string,
            'int', 'integer'  => (int) $string,
            default           => (string) $string,
        };
    }

    // ---------------------------------------------------------------------
    // Display / typography
    // ---------------------------------------------------------------------

    public static function excerpt(
        string $string,
        int $chars = 140,
        bool $strip = true,
        string $rep = ' …'
    ): string {
        if ($strip) {
            $string = preg_replace('#\s*<([^/])#', ' <${1}', $string) ?? $string;
            $string = strip_tags($string);
        }

        $string = str_replace(PHP_EOL, ' ', trim($string));
        $string = preg_replace('![ ]{2,}!', ' ', $string) ?? $string;

        if ($chars === 0) {
            return $string;
        }

        if (self::length($string) <= $chars) {
            return $string;
        }

        $excerpt = self::substr($string, 0, $chars);
        $cutoff  = self::strrpos($excerpt, ' ');

        if ($cutoff !== false) {
            $excerpt = self::substr($string, 0, $cutoff);
        }

        return rtrim($excerpt) . $rep;
    }

    public static function short(?string $string = null, int $length = 0, string $appendix = '…'): string
    {
        $string ??= '';
        if ($length === 0) {
            return $string;
        }

        if (self::length($string) <= $length) {
            return $string;
        }

        return self::substr($string, 0, $length) . $appendix;
    }

    /**
     * Ensure a string doesn't end with a single-word last line.
     */
    public static function widont(?string $string = null): string
    {
        $string = trim($string ?? '');
        if ($string === '') {
            return '';
        }

        $string = preg_replace_callback(
            '|(\S)\s(\S?)$|u',
            static fn(array $m): string => $m[1] . '&nbsp;' . $m[2],
            $string
        ) ?? $string;

        return preg_replace_callback('|(\s)(?=\S*$)(\S+)|u', static function (array $m): string {
            if (self::contains('-', $m[2])) {
                $m[2] = str_replace('-', '&#8209;', $m[2]);
            }
            return '&nbsp;' . $m[2];
        }, $string) ?? $string;
    }

    public static function wrap(string $string, string $before, ?string $after = null): string
    {
        return $before . $string . ($after ?? $before);
    }

    // ---------------------------------------------------------------------
    // Misc
    // ---------------------------------------------------------------------

    public static function increment(string $string, string $separator = '-', int $first = 1): string
    {
        preg_match(
            '/(.+)' . preg_quote($separator, '/') . '([0-9]+)$/',
            $string,
            $matches
        );

        if (isset($matches[2])) {
            $width = strlen($matches[2]);
            $next  = (string) ((int) $matches[2] + 1);
            $next  = str_pad($next, $width, '0', STR_PAD_LEFT);
            return $matches[1] . $separator . $next;
        }

        return $string . $separator . $first;
    }

    public static function uuid(): string
    {
        $uuid = bin2hex(random_bytes(16));

        return sprintf(
            '%08s-%04s-4%03s-%04x-%012s',
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            substr($uuid, 13, 3),
            hexdec(substr($uuid, 16, 4)) & 0x3fff | 0x8000,
            substr($uuid, 20, 12)
        );
    }
}

if (!function_exists('mb_ucfirst')) {
    /**
     * Polyfill for PHP < 8.4. The built-in mb_ucfirst() exists on 8.4+.
     */
    function mb_ucfirst(string $str, ?string $encoding = null): string
    {
        $encoding ??= mb_internal_encoding();
        return mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding)
            . mb_substr($str, 1, null, $encoding);
    }
}