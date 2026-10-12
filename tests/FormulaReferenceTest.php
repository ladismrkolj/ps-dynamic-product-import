<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluator;

class FormulaReferenceTest extends TestCase
{
    private static function text(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $relative);
    }

    public function testEveryRegisteredHelperIsInTheCheatSheetAndTheReadme(): void
    {
        $sheet = self::text('views/templates/admin/source_form.tpl');
        $readme = self::text('README.md');
        foreach (ExpressionEvaluator::HELPERS as $name) {
            self::assertStringContainsString('<code>' . $name . '(', $sheet, $name . ' is missing in the cheat sheet');
            self::assertMatchesRegularExpression('/^\\|[^\\n]*`' . $name . '\\(/m', $readme, $name . ' is missing in the README table');
        }
    }

    public function testEveryDocumentedHelperRowNamesARegisteredHelper(): void
    {
        preg_match_all('/^\| `([a-z]+)\(/m', self::text('README.md'), $matches);
        foreach (array_unique($matches[1]) as $name) {
            self::assertContains($name, ExpressionEvaluator::HELPERS, $name . ' is documented but not registered');
        }
    }

    public function testCheatSheetHasNoRawBracesThatSmartyWouldParse(): void
    {
        $sheet = self::text('views/templates/admin/source_form.tpl');
        $without = preg_replace('/\{(?:ldelim|rdelim|\/?if|else|include|\$)[^}]*\}/', '', $sheet);
        // Everything between the syntax table markers must be free of raw braces.
        $start = strpos($without, '<table class="table table-condensed" aria-label="Formula syntax">');
        $end = strpos($without, '</table>', $start);
        self::assertSame(0, preg_match('/[{}]/', substr($without, $start, $end - $start)));
    }

    /** @return array<string, array{0: string, 1: array, 2: mixed}> the documented examples with their results */
    public static function documentedExamples(): array
    {
        $fields = ['brand' => 'Acme', 'model' => 'X1', 'price' => '10', 'stock' => '3', 'sku' => 'A-12', 'name' => 'Kite Pro', 'size' => 'M', 'qty' => 2, 'sale' => '',
            'active' => 'yes', 'hidden' => false, 'images' => ['a.jpg', 'b.jpg'], 'a' => 'x', 'b' => 'y', 'id' => 5, 'prices' => ['retail' => '9.5'],
            'specification_table' => [['Size' => '9', 'Colour' => 'Blue'], ['Size' => '12.0', 'Colour' => 'Blue', 'Weight' => '3']],
            'variants' => [['size' => '12']], 'attributes' => ['item' => [['@attributes' => ['name' => 'Colour'], '@value' => 'Red']]]];

        return [
            'path' => ["path(fields, 'prices.retail')", $fields, '9.5'],
            'tilde' => ["fields['brand'] ~ ' ' ~ fields['model']", $fields, 'Acme X1'],
            'math' => ["num(fields['price']) * 1.22", $fields, 12.2],
            'compare' => ["num(fields['stock']) > 0", $fields, true],
            'logic' => ["fields['active'] == 'yes' and not fields['hidden']", $fields, true],
            'ternary' => ["num(fields['stock']) > 0 ? 1 : 0", $fields, 1],
            'elvis' => ["fields['sale'] ?: fields['price']", $fields, '10'],
            'coalesce' => ["fields['missing'] ?? 0", $fields, 0],
            'matches' => ["fields['name'] matches '/^Kite/i'", $fields, true],
            'starts with' => ["fields['sku'] starts with 'A-'", $fields, true],
            'contains' => ["fields['name'] contains 'Pro'", $fields, true],
            'in' => ["fields['size'] in ['S', 'M']", $fields, true],
            'range' => ["fields['qty'] in 1..5", $fields, true],
            'not in' => ["fields['brand'] not in ['Nike']", $fields, true],
            'map literal' => ["{'S': 1, 'M': 2}[fields['size']]", $fields, 2],
            'num' => ["num(fields['price'])", $fields, 10.0],
            'str' => ["str(fields['id'])", $fields, '5'],
            'regex' => ["regex('/^A\\\\d+\$/', 'A12')", $fields, true],
            'first' => ["first(fields['images'])", $fields, 'a.jpg'],
            'lower' => ["lower('ČAJ')", $fields, 'čaj'],
            'trim' => ["trim(' x ')", $fields, 'x'],
            'replace' => ["replace('a-b', '-', ' ')", $fields, 'a b'],
            'round' => ["round(num(fields['price']) * 1.22, 2)", $fields, 12.2],
            'join' => ["join([fields['a'], fields['b']], ' / ')", $fields, 'x / y'],
            'pairs' => ["pairs(path(fields, 'attributes.item'), '@attributes.name', '@value')", $fields, ['Colour' => 'Red']],
            'common' => ["common(fields['specification_table'])", $fields, ['Colour' => 'Blue', 'Weight' => '3']],
            'pick' => ["pick(common(fields['specification_table']), {'Colour': 'Color'})", $fields, ['Color' => 'Blue']],
            'row' => ["row(fields['specification_table'], 'Size', path(fields, 'variants.0.size'))", $fields, ['Size' => '12.0', 'Colour' => 'Blue', 'Weight' => '3']],
            'row auto' => ["row(fields['specification_table'], 12)", $fields, ['Size' => '12.0', 'Colour' => 'Blue', 'Weight' => '3']],
            'merge' => ["merge(common(fields['specification_table']), row(fields['specification_table'], 'Size', '9'))", $fields, ['Colour' => 'Blue', 'Weight' => '3', 'Size' => '9']],
            'count' => ["count(fields['images'])", $fields, 2],
            'table' => ["table(fields['specification_table'])", $fields, '<table><thead><tr><th>Size</th><th>Colour</th><th>Weight</th></tr></thead><tbody><tr><td>9</td><td>Blue</td><td></td></tr><tr><td>12.0</td><td>Blue</td><td>3</td></tr></tbody></table>'],
            'table with description' => ["fields['description'] ~ table(fields['nothing'])", $fields, ''],
        ];
    }

    #[DataProvider('documentedExamples')]
    public function testDocumentedExamplesEvaluate(string $expression, array $fields, mixed $expected): void
    {
        self::assertEquals($expected, (new ExpressionEvaluator())->evaluate($expression, ['fields' => $fields]));
    }

    public function testTableEscapesAndSkipsWhatItCannotShow(): void
    {
        $html = ExpressionEvaluator::table([['<b>' => 'a & "b"', 'x' => ['n'], 'y' => ''], 'junk', null, ['z' => 1]]);
        self::assertSame('<table><thead><tr><th>&lt;b&gt;</th><th>z</th></tr></thead><tbody><tr><td>a &amp; &quot;b&quot;</td><td></td></tr><tr><td></td><td>1</td></tr></tbody></table>', $html);
        self::assertSame('<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>', ExpressionEvaluator::table(['A' => '1']));
        self::assertNull(ExpressionEvaluator::table(null));
        self::assertNull(ExpressionEvaluator::table([]));
        self::assertNull(ExpressionEvaluator::table([['x' => '']]));
    }
}
