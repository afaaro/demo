<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels;

/**
 * Shared cURL SSL defaults for marketplace HTTP clients.
 *
 * WAMP often ships cacert.pem but leaves curl.cainfo empty.
 */
final class CurlSsl
{
    /**
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    public static function withSsl(array $options): array
    {
        $options[CURLOPT_SSL_VERIFYPEER] = true;
        $options[CURLOPT_SSL_VERIFYHOST] = 2;

        if (!empty($options[CURLOPT_CAINFO]) && is_string($options[CURLOPT_CAINFO])
            && is_file($options[CURLOPT_CAINFO])
        ) {
            return $options;
        }

        foreach (self::caCandidates() as $path) {
            if (is_file($path)) {
                $options[CURLOPT_CAINFO] = $path;
                break;
            }
        }

        return $options;
    }

    /** @return array<int, string> */
    private static function caCandidates(): array
    {
        $out = [];

        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $ini) {
            $ini = is_string($ini) ? trim($ini) : '';
            if ($ini !== '') {
                $out[] = $ini;
            }
        }

        $out[] = 'C:/wamp64/bin/php/cacert.pem';
        $out[] = 'C:\\wamp64\\bin\\php\\cacert.pem';

        $root = dirname(__DIR__, 4);
        $out[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cacert.pem';

        return $out;
    }
}
