<?php

/**
 * Driver S3 / compatible (MinIO, DigitalOcean Spaces, Backblaze B2).
 * Requiere extensión curl. Sin dependencias Composer adicionales.
 */
class S3StorageDriver implements StorageDriverInterface
{
    private string $bucket;

    private string $region;

    private string $accessKey;

    private string $secretKey;

    private string $endpoint;

    private bool $pathStyle;

    private string $publicBaseUrl;

    /** @var array<string, string> key => temp path */
    private array $tempFiles = [];

    public function __construct()
    {
        $this->bucket = trim((string)(getenv('S3_BUCKET') ?: ''));
        $this->region = trim((string)(getenv('S3_REGION') ?: 'us-east-1'));
        $this->accessKey = trim((string)(getenv('S3_ACCESS_KEY') ?: ''));
        $this->secretKey = trim((string)(getenv('S3_SECRET_KEY') ?: ''));
        $this->endpoint = rtrim(trim((string)(getenv('S3_ENDPOINT') ?: '')), '/');
        $this->pathStyle = in_array(strtolower((string)(getenv('S3_USE_PATH_STYLE'))), ['1', 'true', 'yes'], true);
        $this->publicBaseUrl = rtrim(trim((string)(getenv('S3_PUBLIC_BASE_URL') ?: '')), '/');

        if ($this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException(
                'S3 no configurado: defina S3_BUCKET, S3_ACCESS_KEY y S3_SECRET_KEY en .env'
            );
        }

        if ($this->endpoint === '') {
            $this->endpoint = 'https://s3.' . $this->region . '.amazonaws.com';
        }

        if ($this->publicBaseUrl === '') {
            $this->publicBaseUrl = $this->pathStyle
                ? $this->endpoint . '/' . $this->bucket . '/uploads'
                : 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/uploads';
        }
    }

    public function putContents(string $key, string $contents): void
    {
        $this->request('PUT', $key, $contents, $this->guessContentType($key));
    }

    public function putFile(string $key, string $localSourcePath, bool $move = true): void
    {
        $contents = file_get_contents($localSourcePath);
        if ($contents === false) {
            throw new RuntimeException('Archivo origen no legible.');
        }

        $this->putContents($key, $contents);

        if ($move) {
            @unlink($localSourcePath);
        }
    }

    public function get(string $key): ?string
    {
        $response = $this->request('GET', $key);
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Error S3 GET ' . $key . ': HTTP ' . $response['status']);
        }

        return $response['body'];
    }

    public function exists(string $key): bool
    {
        $response = $this->request('HEAD', $key);

        return $response['status'] === 200;
    }

    public function delete(string $key): bool
    {
        $response = $this->request('DELETE', $key);

        return in_array($response['status'], [200, 204, 404], true);
    }

    public function localPath(string $key): string
    {
        if (isset($this->tempFiles[$key]) && is_readable($this->tempFiles[$key])) {
            return $this->tempFiles[$key];
        }

        $data = $this->get($key);
        if ($data === null) {
            throw new RuntimeException('Archivo no encontrado en S3: ' . $key);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'savid_s3_');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear archivo temporal.');
        }

        $target = $tmp . '_' . basename($key);
        @unlink($tmp);
        if (@file_put_contents($target, $data) === false) {
            throw new RuntimeException('No se pudo materializar archivo temporal.');
        }

        $this->tempFiles[$key] = $target;

        return $target;
    }

    public function listKeys(string $prefix, string $globPattern = '*'): array
    {
        $objectPrefix = $this->objectKey($prefix);
        if ($objectPrefix !== '' && !str_ends_with($objectPrefix, '/')) {
            $objectPrefix .= '/';
        }

        $keys = [];
        $token = '';
        do {
            $query = 'list-type=2&prefix=' . rawurlencode($objectPrefix) . '&max-keys=1000';
            if ($token !== '') {
                $query .= '&continuation-token=' . rawurlencode($token);
            }

            $response = $this->request('GET', '', null, null, $query);
            if ($response['status'] !== 200) {
                break;
            }

            $xml = @simplexml_load_string($response['body']);
            if ($xml === false) {
                break;
            }

            foreach ($xml->Contents ?? [] as $item) {
                $objectKey = (string)($item->Key ?? '');
                $storageKey = $this->storageKeyFromObject($objectKey);
                if ($storageKey === '') {
                    continue;
                }
                $basename = basename($storageKey);
                if ($globPattern !== '*' && !fnmatch($globPattern, $basename)) {
                    continue;
                }
                $keys[] = $storageKey;
            }

            $isTruncated = strtolower((string)($xml->IsTruncated ?? 'false')) === 'true';
            $token = $isTruncated ? (string)($xml->NextContinuationToken ?? '') : '';
        } while ($token !== '');

        sort($keys);

        return $keys;
    }

    public function publicUrl(string $key): ?string
    {
        if (str_starts_with($key, 'sgd_imports/')) {
            return null;
        }

        if (!str_starts_with($key, 'sgd/')
            && !str_starts_with($key, 'empresas/')
            && !str_starts_with($key, 'usuarios/')) {
            return null;
        }

        return $this->publicBaseUrl . '/' . $key;
    }

    public function localDirectory(string $keyPrefix): string
    {
        $dir = sys_get_temp_dir() . '/savid_s3_' . md5($keyPrefix);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio temporal S3.');
        }

        return $dir;
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(
        string $method,
        string $key,
        ?string $body = null,
        ?string $contentType = null,
        string $queryString = ''
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión curl es requerida para STORAGE_DRIVER=s3.');
        }

        $objectKey = $key === '' && $queryString !== '' ? '' : $this->objectKey($key);
        $url = $this->buildUrl($objectKey, $queryString);
        $parsed = parse_url($url);
        $host = (string)($parsed['host'] ?? '');
        $path = (string)($parsed['path'] ?? '/');
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $path .= '?' . $parsed['query'];
        }

        $payload = $body ?? '';
        $payloadHash = hash('sha256', $payload);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $service = 's3';

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];

        if ($contentType !== null && $contentType !== '') {
            $headers['content-type'] = $contentType;
        }

        if ($payload !== '') {
            $headers['content-length'] = (string)strlen($payload);
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string)$value) . "\n";
            $signedHeaderNames[] = strtolower($name);
        }
        sort($signedHeaderNames);
        $signedHeaders = implode(';', $signedHeaderNames);

        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncode($path, false),
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $dateStamp . '/' . $this->region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature
        );

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'host') {
                continue;
            }
            $curlHeaders[] = $name . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $authorization;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADER => true,
        ]);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($payload !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Error curl S3: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => self::parseHeaders($rawHeaders),
        ];
    }

    private function objectKey(string $storageKey): string
    {
        $storageKey = ltrim(str_replace('\\', '/', $storageKey), '/');

        return $storageKey === '' ? '' : 'uploads/' . $storageKey;
    }

    private function storageKeyFromObject(string $objectKey): string
    {
        $objectKey = ltrim($objectKey, '/');
        if (!str_starts_with($objectKey, 'uploads/')) {
            return '';
        }

        return substr($objectKey, strlen('uploads/'));
    }

    private function buildUrl(string $objectKey, string $queryString = ''): string
    {
        $objectKey = ltrim($objectKey, '/');
        if ($this->pathStyle) {
            $base = $this->endpoint . '/' . rawurlencode($this->bucket);
            $url = $objectKey === '' ? $base : $base . '/' . str_replace('%2F', '/', rawurlencode($objectKey));
        } else {
            $base = $this->endpoint;
            if (!str_contains($base, '://')) {
                $base = 'https://' . $base;
            }
            if (!str_contains($base, $this->bucket)) {
                $host = parse_url($base, PHP_URL_HOST);
                $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
                $base = $scheme . '://' . $this->bucket . '.' . $host;
            }
            $url = $objectKey === '' ? $base : $base . '/' . str_replace('%2F', '/', rawurlencode($objectKey));
        }

        if ($queryString !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
        }

        return $url;
    }

    private function signingKey(string $dateStamp, string $service): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function uriEncode(string $path, bool $encodeSlash = true): string
    {
        $parts = explode('/', $path);
        $encoded = [];
        foreach ($parts as $part) {
            if ($part === '') {
                $encoded[] = '';
                continue;
            }
            $encoded[] = rawurlencode($part);
        }
        $join = implode('/', $encoded);

        return $encodeSlash ? $join : str_replace('%2F', '/', $join);
    }

    private function guessContentType(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'json' => 'application/json',
            'html' => 'text/html',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
