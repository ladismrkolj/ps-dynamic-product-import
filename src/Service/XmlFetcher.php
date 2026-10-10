<?php

namespace ProductImport\Service;

class XmlFetcher
{
    private FeedDownloader $downloader;

    public function __construct(?FeedDownloader $downloader = null)
    {
        $this->downloader = $downloader ?? new FeedDownloader();
    }

    public function fetch(?string $url, ?string $filePath, ?string $itemPath): array
    {
        if ($url !== null && trim($url) !== '') {
            $body = $this->fetchUrl($url);
        } elseif ($filePath !== null && trim($filePath) !== '') {
            $body = $this->downloader->readFile($filePath, 'XML');
        } else {
            throw new \InvalidArgumentException('Provide an XML URL or file path.');
        }

        return $this->parse($body, $itemPath);
    }

    public function parse(string $body, ?string $itemPath): array
    {
        if ($itemPath === null || trim($itemPath) === '') {
            throw new \InvalidArgumentException('Provide an XML item path.');
        }
        $path = trim($itemPath);
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $segment)) {
                throw new \InvalidArgumentException('Invalid XML item path: ' . $path);
            }
        }
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                $errors = array_map(static fn ($error) => trim($error->message), libxml_get_errors());
                throw new \RuntimeException('Invalid XML: ' . implode('; ', array_unique($errors)));
            }
            $nodes = [$xml];
            foreach ($segments as $segment) {
                $next = [];
                foreach ($nodes as $node) {
                    foreach ($node->children() as $child) {
                        if ($child->getName() === $segment) {
                            $next[] = $child;
                        }
                    }
                }
                $nodes = $next;
            }
            if ($nodes === []) {
                throw new \RuntimeException("No items found at path '" . $path . "'");
            }
            $items = array_map(fn ($node) => $this->elementToArray($node), $nodes);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('XML items must contain child elements or attributes.');
                }
            }
            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @attributes and @value are synthetic keys for an element's own XML attributes
     * and its text when it has attributes; XML child names cannot start with @.
     */
    private function elementToArray(\SimpleXMLElement $element): array|string
    {
        $result = [];
        $attributes = [];
        foreach ($element->attributes() as $name => $value) {
            $attributes[$name] = (string) $value;
        }
        if ($attributes !== []) {
            $result['@attributes'] = $attributes;
        }
        $seen = [];
        $hasChildren = false;
        foreach ($element->children() as $child) {
            $hasChildren = true;
            $name = $child->getName();
            $value = $this->elementToArray($child);
            if (!isset($seen[$name])) {
                $seen[$name] = 1;
                $result[$name] = $value;
            } elseif ($seen[$name] > 1) {
                ++$seen[$name];
                $result[$name][] = $value;
            } else {
                ++$seen[$name];
                $result[$name] = [$result[$name], $value];
            }
        }
        if ($attributes !== [] && !$hasChildren) {
            $result['@value'] = trim((string) $element);
        }
        return $result === [] ? trim((string) $element) : $result;
    }

    private function fetchUrl(string $url): string
    {
        return $this->downloader->fetchUrl($url, 'XML');
    }
}
