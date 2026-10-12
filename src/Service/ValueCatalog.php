<?php

namespace ProductImport\Service;

/**
 * Every distinct value of a feed field or feature over the whole feed, for writing formulas. Values equal ignoring
 * case and whitespace are grouped (the most frequent spelling leads, the others are listed). No writes.
 */
class ValueCatalog
{
    public const MAX_EVALUATIONS = 100000;
    public const MAX_VALUES = 1000;

    private ExpressionEvaluator $evaluator;

    public function __construct(?ExpressionEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator ?? new ExpressionEvaluator();
    }

    /** Display text of one formula result, null for no value. */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param array<string, array{count:int,p:int,v:int}> $raw exact value => counts (products having it; P = product formula, V = variants)
     *
     * @return list<array{value:string,count:int,p:int,v:int,also:list<array{value:string,count:int}>}> most frequent first
     */
    public static function group(array $raw): array
    {
        $groups = [];
        foreach ($raw as $value => $info) {
            $key = FeatureValueMatcher::normalize((string) $value);
            $groups[$key][] = ['value' => (string) $value] + $info;
        }
        $out = [];
        foreach ($groups as $members) {
            usort($members, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['value'], $b['value']));
            $lead = array_shift($members);
            $count = $lead['count'];
            $p = $lead['p'];
            $v = $lead['v'];
            $also = [];
            foreach ($members as $member) {
                $count += $member['count'];
                $p += $member['p'];
                $v += $member['v'];
                $also[] = ['value' => $member['value'], 'count' => $member['count']];
            }
            $out[] = ['value' => $lead['value'], 'count' => $count, 'p' => $p, 'v' => $v, 'also' => $also];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['value'], $b['value']));

        return $out;
    }

    /**
     * What a feature value formula makes of one value.
     *
     * @param (callable(string):mixed)|null $formula
     * @param array<string,true> $existing normalised existing values of the target feature
     *
     * @return array{kind:string,text:string,match:bool} kind: value | skipped | error
     */
    public static function result(string $value, ?callable $formula, array $existing = []): array
    {
        try {
            $out = NativeFields::featureValue($formula === null ? $value : $formula($value));
        } catch (\Throwable $error) {
            return ['kind' => 'error', 'text' => mb_substr($error->getMessage(), 0, 300), 'match' => false];
        }
        if ($out === null) {
            return ['kind' => 'skipped', 'text' => '', 'match' => false];
        }

        return ['kind' => 'value', 'text' => $out, 'match' => isset($existing[FeatureValueMatcher::normalize($out)])];
    }

    /**
     * Distinct results of a field formula (product level, or per variant when $variantsExpression is given).
     *
     * @return array{items_scanned:int,evaluations:int,truncated:bool,empty:int,distinct_total:int,values:list<array>,errors:list<array{message:string,count:int}>}
     */
    public function scanField(array $items, string $expression, ?string $variantsExpression, ?string $filter): array
    {
        $raw = [];
        $errors = [];
        $empty = 0;
        $evaluations = 0;
        $truncated = false;
        $scanned = 0;
        $mapper = new VariantFieldMapper($this->evaluator);
        foreach ($items as $item) {
            if (!is_array($item) || !$this->passes($item, $filter)) {
                continue;
            }
            if ($evaluations >= self::MAX_EVALUATIONS) {
                $truncated = true;
                break;
            }
            ++$scanned;
            try {
                $sets = [['fields' => $item]];
                if ($variantsExpression !== null) {
                    $sets = array_map(static fn (array $variant): array => ['fields' => $item, 'variant' => $variant], $mapper->variants($item, $variantsExpression));
                }
                foreach ($sets as $variables) {
                    ++$evaluations;
                    $text = self::text($this->evaluator->evaluate($expression, $variables));
                    if ($text === null || $text === '') {
                        ++$empty;
                        continue;
                    }
                    $raw[$text] ??= ['count' => 0, 'p' => 0, 'v' => 0];
                    ++$raw[$text]['count'];
                }
            } catch (\Throwable $error) {
                $message = mb_substr($error->getMessage(), 0, 300);
                $errors[$message] = ($errors[$message] ?? 0) + 1;
            }
        }

        return $this->field($raw, $errors, $empty, $scanned, $evaluations, $truncated);
    }

    private function field(array $raw, array $errors, int $empty, int $scanned, int $evaluations, bool $truncated): array
    {
        $groups = self::group($raw);
        arsort($errors);
        $list = [];
        foreach ($errors as $message => $count) {
            $list[] = ['message' => (string) $message, 'count' => $count];
        }

        return ['items_scanned' => $scanned, 'evaluations' => $evaluations, 'truncated' => $truncated, 'empty' => $empty, 'distinct_total' => count($groups),
            'values' => array_slice($groups, 0, self::MAX_VALUES), 'errors' => array_slice($list, 0, 20)];
    }

    /**
     * Every raw value of one feed feature name: product formula (P) and variants formula (V), counted per product.
     *
     * @return array{items_scanned:int,evaluations:int,truncated:bool,distinct_total:int,values:list<array>}
     */
    public function scanFeature(array $items, string $name, ?string $productExpression, ?string $variantsExpression, ?string $variantFeaturesExpression, ?string $filter): array
    {
        $raw = [];
        $evaluations = 0;
        $truncated = false;
        $scanned = 0;
        $mapper = new VariantFieldMapper($this->evaluator);
        foreach ($items as $item) {
            if (!is_array($item) || !$this->passes($item, $filter)) {
                continue;
            }
            if ($evaluations >= self::MAX_EVALUATIONS) {
                $truncated = true;
                break;
            }
            ++$scanned;
            $perProduct = [];
            try {
                if ($productExpression !== null) {
                    ++$evaluations;
                    $value = $this->evaluator->evaluate($productExpression, ['fields' => $item]);
                    if ($value !== null) {
                        foreach (NativeFields::features($value)['pairs'] as $feed => $text) {
                            if ((string) $feed === $name) {
                                $perProduct[$text]['p'] = true;
                            }
                        }
                    }
                }
                if ($variantsExpression !== null && $variantFeaturesExpression !== null) {
                    foreach ($mapper->variants($item, $variantsExpression) as $variant) {
                        ++$evaluations;
                        $value = $this->evaluator->evaluate($variantFeaturesExpression, ['fields' => $item, 'variant' => $variant]);
                        if ($value !== null) {
                            foreach (NativeFields::features($value)['pairs'] as $feed => $text) {
                                if ((string) $feed === $name) {
                                    $perProduct[$text]['v'] = true;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
            foreach ($perProduct as $text => $origin) {
                $raw[(string) $text] ??= ['count' => 0, 'p' => 0, 'v' => 0];
                ++$raw[(string) $text]['count'];
                $raw[(string) $text]['p'] += isset($origin['p']) ? 1 : 0;
                $raw[(string) $text]['v'] += isset($origin['v']) ? 1 : 0;
            }
        }
        $groups = self::group($raw);

        return ['items_scanned' => $scanned, 'evaluations' => $evaluations, 'truncated' => $truncated, 'distinct_total' => count($groups), 'values' => array_slice($groups, 0, self::MAX_VALUES)];
    }

    private function passes(array $item, ?string $filter): bool
    {
        if ($filter === null) {
            return true;
        }
        try {
            return (bool) $this->evaluator->evaluate($filter, ['fields' => $item]);
        } catch (\Throwable) {
            return false;
        }
    }
}
