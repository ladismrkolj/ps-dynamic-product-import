<?php

namespace ProductImport\Service;

/** Classifies a source location as a downloadable URL or a file inside the allowed import folder. */
class FeedLocation
{
    public const CONFIG_KEY = 'PIIMPORT_FEED_DIR';

    /** Import folder from module configuration; empty when unset. */
    public static function feedDir(): string
    {
        return class_exists('Configuration', false) ? (string) \Configuration::get(self::CONFIG_KEY) : '';
    }

    /**
     * @return array{type: 'url'|'file', value: string} value is the URL, or the real absolute file path
     * @throws \InvalidArgumentException
     */
    public static function resolve(string $location, ?string $feedDir = null): array
    {
        $location = trim($location);
        if ($location === '') {
            throw new \InvalidArgumentException('Enter a source: an http(s) URL or a file path inside the import folder.');
        }
        if (str_contains($location, "\0")) {
            throw new \InvalidArgumentException('The source contains an invalid character.');
        }
        // A scheme of 2+ letters; a single letter is a Windows drive and is treated as a path (then rejected as outside).
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]+):#', $location, $match)) {
            $scheme = strtolower($match[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new \InvalidArgumentException("Unsupported source scheme '" . $scheme . "://'. Use http(s):// URLs or a file path inside the import folder.");
            }
            $parts = parse_url($location);
            if ($parts === false || !preg_match('#^https?://[^/\s]#i', $location) || empty($parts['host'])) {
                throw new \InvalidArgumentException('The source URL is not valid: ' . $location);
            }

            return ['type' => 'url', 'value' => $location];
        }

        $feedDir = $feedDir ?? self::feedDir();
        $root = $feedDir === '' ? false : realpath($feedDir);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('The import folder is missing or does not exist: ' . ($feedDir === '' ? '(not configured)' : $feedDir) . '. Create it or set it on the source list page.');
        }
        $root = rtrim($root, '/');

        $candidate = $location[0] === '/' ? $location : $root . '/' . $location;
        $real = self::realpathAllowingMissingTail($candidate);
        if ($real === null || !str_starts_with($real, $root . '/')) {
            throw new \InvalidArgumentException('The file path must be inside the import folder ' . $root . ': ' . $location);
        }

        return ['type' => 'file', 'value' => $real];
    }

    /** realpath() for a path whose last segments may not exist yet; null if a missing segment is '..' or '.'. */
    private static function realpathAllowingMissingTail(string $path): ?string
    {
        $tail = [];
        $existing = rtrim($path, '/');
        while ($existing !== '' && ($real = realpath($existing)) === false) {
            $segment = basename($existing);
            if ($segment === '..' || $segment === '.') {
                return null;
            }
            array_unshift($tail, $segment);
            $existing = dirname($existing);
        }
        if ($existing === '') {
            $real = '/';
        }

        return rtrim($real, '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
    }
}
