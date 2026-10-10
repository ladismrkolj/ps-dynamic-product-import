<?php

namespace ProductImport\Service\Format;

/** Registry of supported feed formats. Adding a format = one class plus one entry in CLASSES. */
class FeedFormats
{
    private const CLASSES = [JsonFormat::class, XmlFormat::class];

    /** @return array<string, FeedFormat> keyed by format name */
    public static function all(): array
    {
        $formats = [];
        foreach (self::CLASSES as $class) {
            $format = new $class();
            $formats[$format->name()] = $format;
        }

        return $formats;
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    public static function get(string $name): FeedFormat
    {
        $formats = self::all();
        if (!isset($formats[$name])) {
            throw new \InvalidArgumentException("Unknown source format '" . $name . "'. Available: " . implode(', ', array_keys($formats)) . '.');
        }

        return $formats[$name];
    }

    /** @return array<string, string> format name => label, for the form select */
    public static function choices(): array
    {
        return array_map(static fn (FeedFormat $format): string => $format->label(), self::all());
    }
}
