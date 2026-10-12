<?php

namespace ProductImport\Service;

/**
 * Pure decision of what happens to each feed feature of one product. A mapping row names an action for a feed name:
 * `none` (the source setting decides), `ignore`, `map` (an existing feature) or `create` (a feature of the given name,
 * connected to its id by the importer once it exists). A value formula can rewrite the value or, by returning null or
 * an empty value, skip the feature for the product. When several feed names end up in the same feature the first one
 * in feed order wins.
 */
final class FeatureMapping
{
    public const NONE = 'none';
    public const IGNORE = 'ignore';
    public const MAP = 'map';
    public const CREATE = 'create';
    public const ACTIONS = [self::NONE, self::IGNORE, self::MAP, self::CREATE];
    public const UNMAPPED_IGNORE = 'ignore';
    public const UNMAPPED_CREATE = 'create';
    public const UNMAPPED = [self::UNMAPPED_IGNORE, self::UNMAPPED_CREATE];

    /**
     * @param array<string,string> $pairs feed name => value, as normalised by NativeFields::features()
     * @param array<string,array{action?:string,id_feature?:int|string|null,create_name?:?string,value_expression?:?string}> $rules mapping rows by feed name
     * @param callable(string,string,string):mixed $transform fn(feed name, value expression, value) evaluates a value formula
     *
     * @return array{
     *     entries: list<array{name:string,value:string,id_feature:?int,create:?string,connect:bool}>,
     *     ignored: list<string>, unmapped: list<string>, skipped: list<string>, errors: list<string>, conflicts: list<string>
     * } ignored: names with the action ignore; unmapped: names left out by the source setting;
     *   skipped: names whose value formula gave no value; errors: "name: message"; conflicts: one message per lost duplicate
     */
    public static function plan(array $pairs, array $rules, string $unmapped, callable $transform): array
    {
        $plan = ['entries' => [], 'ignored' => [], 'unmapped' => [], 'skipped' => [], 'errors' => [], 'conflicts' => []];
        $winners = [];
        foreach ($pairs as $name => $value) {
            $name = (string) $name;
            $rule = self::effective($rules[$name] ?? null);
            if ($rule['action'] === self::IGNORE) {
                $plan['ignored'][] = $name;
                continue;
            }
            if ($rule['action'] === self::NONE && $unmapped !== self::UNMAPPED_CREATE) {
                $plan['unmapped'][] = $name;
                continue;
            }
            $value = (string) $value;
            if ($rule['value_expression'] !== null) {
                try {
                    $value = NativeFields::featureValue($transform($name, $rule['value_expression'], $value));
                } catch (\Throwable $error) {
                    $plan['errors'][] = $name . ': ' . $error->getMessage();
                    continue;
                }
                if ($value === null) {
                    $plan['skipped'][] = $name;
                    continue;
                }
            }
            $idFeature = $rule['action'] === self::MAP ? $rule['id_feature'] : null;
            $create = $rule['action'] === self::MAP ? null : ($rule['action'] === self::CREATE ? $rule['create_name'] : $name);
            $key = $idFeature !== null ? 'id:' . $idFeature : 'name:' . $create;
            if (isset($winners[$key])) {
                $plan['conflicts'][] = 'feed names "' . $winners[$key] . '" and "' . $name . '" both map to the feature '
                    . ($idFeature !== null ? '#' . $idFeature : '"' . $create . '"') . '; "' . $winners[$key] . '" is used';
                continue;
            }
            $winners[$key] = $name;
            $plan['entries'][] = ['name' => $name, 'value' => $value, 'id_feature' => $idFeature, 'create' => $create, 'connect' => $rule['action'] === self::CREATE];
        }

        return $plan;
    }

    /**
     * A row that cannot act (map without a feature, create without a name, unknown action) counts as not mapped.
     *
     * @return array{action:string,id_feature:?int,create_name:?string,value_expression:?string}
     */
    public static function effective(?array $row): array
    {
        $action = (string) ($row['action'] ?? self::NONE);
        $idFeature = isset($row['id_feature']) && (int) $row['id_feature'] > 0 ? (int) $row['id_feature'] : null;
        $createName = isset($row['create_name']) && trim((string) $row['create_name']) !== '' ? trim((string) $row['create_name']) : null;
        $expression = isset($row['value_expression']) && trim((string) $row['value_expression']) !== '' ? trim((string) $row['value_expression']) : null;
        if (($action === self::MAP && $idFeature === null) || ($action === self::CREATE && $createName === null) || !in_array($action, self::ACTIONS, true)) {
            $action = self::NONE;
        }

        return ['action' => $action, 'id_feature' => $idFeature, 'create_name' => $createName, 'value_expression' => $expression];
    }
}
