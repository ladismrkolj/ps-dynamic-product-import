<?php

namespace ProductImport\Service;

/** Shared feed transfer for JSON/XML: bounded retries on transient failures and a body size cap. */
class FeedDownloader
{
    public const DEFAULT_MAX_BYTES = 209715200; // 200 MB

    private int $maxBytes;
    /** @var list<int> seconds to wait before each retry */
    private array $backoff;
    /** @var callable(int): void */
    private $sleep;
    /** @var callable(string, int): array{status: int, body: string, error: ?string, too_large: bool} */
    private $transport;

    public function __construct(int $maxBytes = self::DEFAULT_MAX_BYTES, array $backoff = [2, 5], ?callable $sleep = null, ?callable $transport = null)
    {
        $this->maxBytes = max(1, $maxBytes);
        $this->backoff = array_values($backoff);
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    /** Network errors, HTTP 5xx and 429 are transient; 4xx and oversized bodies are not. */
    public static function isRetryable(int $status, ?string $networkError, bool $tooLarge = false): bool
    {
        if ($tooLarge) {
            return false;
        }
        if ($networkError !== null) {
            return true;
        }

        return $status === 429 || $status >= 500;
    }

    public function fetchUrl(string $url, string $label): string
    {
        $attempt = 0;
        while (true) {
            $result = ($this->transport)($url, $this->maxBytes);
            if ($result['too_large']) {
                throw new \RuntimeException($label . ' response exceeds the ' . self::formatBytes($this->maxBytes) . ' size limit; aborted. Split the feed or reduce its size.');
            }
            $failure = null;
            if ($result['error'] !== null) {
                $failure = $label . ' request failed: ' . $result['error'];
            } elseif ($result['status'] < 200 || $result['status'] >= 300) {
                $failure = $label . ' request returned HTTP ' . $result['status'] . '.';
            } else {
                return $result['body'];
            }
            if (!self::isRetryable($result['status'], $result['error']) || !isset($this->backoff[$attempt])) {
                throw new \RuntimeException($failure . ($attempt > 0 ? ' (gave up after ' . ($attempt + 1) . ' attempts)' : ''));
            }
            ($this->sleep)((int) $this->backoff[$attempt]);
            ++$attempt;
        }
    }

    public function readFile(string $path, string $label): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException($label . ' file is not a readable regular file: ' . $path);
        }
        $size = @filesize($path);
        if ($size !== false && $size > $this->maxBytes) {
            throw new \RuntimeException($label . ' file exceeds the ' . self::formatBytes($this->maxBytes) . ' size limit: ' . $path);
        }
        $body = @file_get_contents($path, false, null, 0, $this->maxBytes + 1);
        if ($body === false) {
            throw new \RuntimeException('Unable to read ' . $label . ' file: ' . $path);
        }
        if (strlen($body) > $this->maxBytes) {
            throw new \RuntimeException($label . ' file exceeds the ' . self::formatBytes($this->maxBytes) . ' size limit: ' . $path);
        }

        return $body;
    }

    private static function formatBytes(int $bytes): string
    {
        return round($bytes / 1048576) . ' MB';
    }

    /** @return array{status: int, body: string, error: ?string, too_large: bool} */
    private function curlTransport(string $url, int $maxBytes): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }
        $body = '';
        $tooLarge = false;
        $protocolOption = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS;
        $protocolValue = defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            $protocolOption => $protocolValue,
            // Returning a short count makes cURL abort, so the cap holds while downloading.
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => $body, 'error' => $ok === false && !$tooLarge ? curl_error($handle) : null, 'too_large' => $tooLarge];
    }
}
