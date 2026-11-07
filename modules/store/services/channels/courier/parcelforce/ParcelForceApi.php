<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Courier\ParcelForce;

use Module\Store\Services\Channels\CurlSsl;
use RuntimeException;

/**
 * ParcelForce expressLink — SOAP createShipment / printLabel.
 */
final class ParcelForceApi
{
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private string $username,
        private string $password,
        private string $endpoint = 'https://expresslink.parcelforce.net/ws/',
        private string $namespace = 'http://www.parcelforce.net/ws/ship/v14',
    ) {}

    /**
     * @param array<string, mixed> $requestedShipment
     * @return array<string, mixed>
     */
    public function createShipment(array $requestedShipment): array
    {
        $inner = $this->authXml()
            . '<ns:RequestedShipment>' . $this->arrayToXml($requestedShipment) . '</ns:RequestedShipment>';
        $xml = $this->envelope('CreateShipmentRequest', $inner);
        $raw = $this->soapCall('createShipment', $xml);
        return $this->parseSoapXml($raw);
    }

    /**
     * @return array{binary: string, content_type: string, shipment_number?: string}
     */
    public function printLabel(string $shipmentNumber): array
    {
        $shipmentNumber = trim($shipmentNumber);
        if ($shipmentNumber === '') {
            throw new RuntimeException('ParcelForce shipment number is required.');
        }

        $inner = $this->authXml()
            . '<ns:ShipmentNumber>' . htmlspecialchars($shipmentNumber, ENT_XML1) . '</ns:ShipmentNumber>';
        $xml = $this->envelope('PrintLabelRequest', $inner);
        $raw = $this->soapCall('printLabel', $xml);
        $parsed = $this->parseSoapXml($raw);

        $b64 = (string) (
            $parsed['Label']
            ?? $parsed['label']
            ?? $parsed['Data']
            ?? $parsed['CompletedShipmentInfo']['Label']
            ?? ''
        );
        // Nested walk for Label image.
        if ($b64 === '') {
            $b64 = $this->findFirstString($parsed, ['Label', 'label', 'LabelImage', 'Data']);
        }
        if ($b64 === '') {
            throw new RuntimeException('ParcelForce printLabel response contained no label data.');
        }

        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new RuntimeException('ParcelForce label base64 decode failed.');
        }

        return [
            'binary'          => $bin,
            'content_type'    => str_starts_with($bin, '%PDF') ? 'application/pdf' : 'application/octet-stream',
            'shipment_number' => $shipmentNumber,
        ];
    }

    private function authXml(): string
    {
        return '<ns:Authentication>'
            . '<ns:UserName>' . htmlspecialchars($this->username, ENT_XML1) . '</ns:UserName>'
            . '<ns:Password>' . htmlspecialchars($this->password, ENT_XML1) . '</ns:Password>'
            . '</ns:Authentication>';
    }

    private function envelope(string $requestElement, string $innerXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:ns="' . htmlspecialchars($this->namespace, ENT_XML1) . '">'
            . '<soapenv:Header/><soapenv:Body>'
            . '<ns:' . $requestElement . '>' . $innerXml . '</ns:' . $requestElement . '>'
            . '</soapenv:Body></soapenv:Envelope>';
    }

    private function soapCall(string $soapAction, string $xml): string
    {
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for ParcelForce.');
        }

        curl_setopt_array($ch, CurlSsl::withSsl([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $soapAction . '"',
                'User-Agent: Store/1.0',
            ],
        ]));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('ParcelForce SOAP cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(
                'ParcelForce SOAP failed (HTTP ' . $code . '): ' . substr((string) $raw, 0, 240)
            );
        }

        $raw = (string) $raw;
        if (stripos($raw, 'Fault') !== false && stripos($raw, 'faultstring') !== false) {
            if (preg_match('/<faultstring[^>]*>([^<]+)</i', $raw, $m)) {
                throw new RuntimeException('ParcelForce SOAP fault: ' . html_entity_decode($m[1]));
            }
            throw new RuntimeException('ParcelForce SOAP fault: ' . substr($raw, 0, 240));
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSoapXml(string $raw): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($raw);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new RuntimeException('ParcelForce returned invalid XML.');
        }

        $doc->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $bodies = $doc->xpath('//soap:Body/*') ?: [];
        if ($bodies === []) {
            $bodies = $doc->xpath('//*[local-name()="Body"]/*') ?: [];
        }
        $node = $bodies[0] ?? $doc;
        $json = json_encode($node);
        $arr = $json !== false ? json_decode($json, true) : null;
        return is_array($arr) ? $arr : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToXml(array $data): string
    {
        $xml = '';
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                // List of similar children (numeric keys).
                if (array_is_list($value)) {
                    foreach ($value as $child) {
                        if (is_array($child)) {
                            $xml .= '<ns:' . $key . '>' . $this->arrayToXml($child) . '</ns:' . $key . '>';
                        } else {
                            $xml .= '<ns:' . $key . '>'
                                . htmlspecialchars((string) $child, ENT_XML1)
                                . '</ns:' . $key . '>';
                        }
                    }
                    continue;
                }
                $xml .= '<ns:' . $key . '>' . $this->arrayToXml($value) . '</ns:' . $key . '>';
                continue;
            }
            $xml .= '<ns:' . $key . '>'
                . htmlspecialchars((string) $value, ENT_XML1)
                . '</ns:' . $key . '>';
        }
        return $xml;
    }

    /**
     * @param array<string, mixed> $arr
     * @param list<string> $keys
     */
    private function findFirstString(array $arr, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($arr[$key]) && is_scalar($arr[$key]) && trim((string) $arr[$key]) !== '') {
                return trim((string) $arr[$key]);
            }
        }
        foreach ($arr as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->findFirstString($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }
}
