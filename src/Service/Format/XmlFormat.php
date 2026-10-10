<?php

namespace ProductImport\Service\Format;

class XmlFormat implements FeedFormat
{
    public function name(): string
    {
        return 'xml';
    }

    public function label(): string
    {
        return 'XML';
    }

    public function options(): array
    {
        return ['xml_item_path' => ['label' => 'XML item path', 'required' => true]];
    }

    /**
     * Candidate item paths of an XML body, best first.
     *
     * @return list<array{path: string, count: int, fields: list<string>, approximate: bool}>
     */
    public function detectItemPaths(string $body): array
    {
        return XmlItemPathDetector::detect($body);
    }

    public function parse(string $body, array $source): array
    {
        $itemPath = $source['xml_item_path'] ?? null;
        if (!is_string($itemPath) || trim($itemPath) === '') {
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
}
