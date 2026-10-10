<?php

namespace ProductImport\Service;

class ValueSampler
{
    /** With $variantsExpression, $limit counts variants (not products) and the formula sees both `fields` and `variant`. */
    public function sample(array $items, string $expression, ?string $variantsExpression = null, int $limit = 50): array
    {
        if ($variantsExpression !== null) {
            return $this->sampleVariants($items, $expression, $variantsExpression, $limit);
        }

        $evaluator = new ExpressionEvaluator();
        $counts = [];
        $failed = 0;
        $errors = [];
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Item is not an object.');
                }
                $this->count($counts, $evaluator->evaluate($expression, ['fields' => $item]));
            } catch (\Throwable $error) {
                $this->recordError($failed, $errors, 'Item ' . $index, $error);
            }
        }

        return ['items_scanned' => count($items)] + $this->summary($counts, $failed, $errors);
    }

    private function sampleVariants(array $items, string $expression, string $variantsExpression, int $limit): array
    {
        $evaluator = new ExpressionEvaluator();
        $mapper = new VariantFieldMapper($evaluator);
        $counts = [];
        $failed = 0;
        $errors = [];
        $itemsScanned = 0;
        $variantsScanned = 0;
        foreach ($items as $index => $item) {
            if ($variantsScanned >= $limit) {
                break;
            }
            ++$itemsScanned;
            try {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Item is not an object.');
                }
                $variants = $mapper->variants($item, $variantsExpression);
            } catch (\Throwable $error) {
                $this->recordError($failed, $errors, 'Item ' . $index, $error);
                continue;
            }
            foreach ($variants as $variantIndex => $variant) {
                if ($variantsScanned >= $limit) {
                    break;
                }
                ++$variantsScanned;
                try {
                    $this->count($counts, $evaluator->evaluate($expression, ['fields' => $item, 'variant' => $variant]));
                } catch (\Throwable $error) {
                    $this->recordError($failed, $errors, 'Item ' . $index . ' variant ' . $variantIndex, $error);
                }
            }
        }

        return ['items_scanned' => $itemsScanned, 'variants_scanned' => $variantsScanned] + $this->summary($counts, $failed, $errors);
    }

    private function recordError(int &$failed, array &$errors, string $where, \Throwable $error): void
    {
        ++$failed;
        if (count($errors) < 20) {
            $errors[] = $where . ': ' . mb_substr($error->getMessage(), 0, 500);
        }
    }

    private function count(array &$counts, $value): void
    {
        if ($value === null) {
            return;
        }
        if (is_array($value)) {
            $key = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } elseif (is_bool($value)) {
            $key = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $key = (string) $value;
        } else {
            throw new \InvalidArgumentException('Expression result must be a scalar, array, or null.');
        }
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function summary(array $counts, int $failed, array $errors): array
    {
        arsort($counts);
        $values = [];
        foreach (array_slice($counts, 0, 50, true) as $value => $count) {
            $values[] = ['value' => (string) $value, 'count' => $count];
        }

        return ['errors' => ['count' => $failed, 'messages' => $errors],
            'values' => $values, 'distinct_total' => count($counts), 'truncated' => count($counts) > 50];
    }
}
