<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluationException;
use ProductImport\Service\ExpressionEvaluator;

class ExpressionEvaluatorTest extends TestCase
{
    #[DataProvider('helperCases')]
    public function testHelpers(string $expression, array $fields, $expected): void
    {
        self::assertSame($expected, (new ExpressionEvaluator())->evaluate($expression, ['fields' => $fields]));
    }

    public static function helperCases(): array
    {
        return [
            ['path(fields, "breadcrumbs.0.title")', ['breadcrumbs' => [['title' => 'Sails']]], 'Sails'],
            ['path(fields, "missing.deep")', [], null],
            ['path(fields, "price.deep")', ['price' => 12], null],
            ['path(fields, "empty.deep")', ['empty' => null], null],
            ['path(fields, "flag")', ['flag' => false], false],
            ['num(path(fields, "price"))', ['price' => '12.50'], 12.5],
            ['num(12)', [], 12.0], ['num(null)', [], null],
            ['num("EUR 12")', [], null], ['num([])', [], null],
            ['str(12)', [], '12'], ['str(null)', [], null], ['str(false)', [], ''],
            ['regex("/stock/i", "InStock")', [], true],
            ['regex("/yes/", "no")', [], false], ['regex("/yes/", null)', [], false],
            ['first(["Sails", "Boards"])', [], 'Sails'], ['first([])', [], null],
            ['first(null)', [], null], ['first("Sails")', [], null],
            ['first(fields)', ['named' => ['title' => 'Sails']], ['title' => 'Sails']],
            ['lower("ČAJ")', [], 'čaj'], ['upper("čaj ß")', [], 'ČAJ SS'], ['lower(null)', [], null], ['upper(null)', [], null],
            ['lower(12)', [], '12'],
            ["trim(\"  \u{00A0}Čaj\n \")", [], 'Čaj'], ['trim("")', [], ''], ['trim(null)', [], null],
            ['replace("a-b-c", "-", "+")', [], 'a+b+c'], ['replace("a.b", ".", "")', [], 'ab'],
            ['replace("a.b", "/./", "x")', [], 'a.b'], ['replace(null, "a", "b")', [], null],
            ['round(2.5)', [], 3.0], ['round(-2.5)', [], -3.0], ['round(1.2345, 2)', [], 1.23],
            ['round(1.235, 2)', [], 1.24], ['round("12.345", 1)', [], 12.3], ['round(1234, -2)', [], 1200.0],
            ['round(null)', [], null], ['round(num("x"))', [], null],
            ['join(["a", null, "", "b", 3], "|")', [], 'a|b|3'], ['join(["a", "b"])', [], 'a, b'],
            ['join([null, ""])', [], ''], ['join(null)', [], null],
            ['count([1, 2, 3])', [], 3], ['count([])', [], 0], ['count(null)', [], 0],
            ['count(path(fields, "missing"))', [], 0],
            ['pairs(path(fields, "l"), "n", "v")', ['l' => [['n' => 'Color', 'v' => 'Red'], ['n' => 'Size', 'v' => 'L']]], ['Color' => 'Red', 'Size' => 'L']],
            ['pairs(path(fields, "l"), "n", "v")', ['l' => ['n' => 'Color', 'v' => 'Red']], ['Color' => 'Red']],
            ['pairs(path(fields, "l"), "n", "v")', [], null],
            ['pairs(path(fields, "l"), "n", "v")', ['l' => []], []],
            ['pairs(path(fields, "l"), "a.b", "c.d")', ['l' => [['a' => ['b' => ' K '], 'c' => ['d' => 5]]]], ['K' => 5]],
            ['pairs(path(fields, "l"), "n", "v")', ['l' => [['n' => '', 'v' => 'x'], ['v' => 'y'], 'junk', ['n' => 'A']]], ['A' => null]],
            ['pairs(path(fields, "l"), "@attributes.naziv", "@value")', ['l' => [['@attributes' => ['naziv' => 'Dolžina'], '@value' => '2 m']]], ['Dolžina' => '2 m']],
            ['common(path(fields, "l"))', ['l' => [['Discipline' => 'Pump foil'], ['Weight (kg)' => '0.29']]], ['Discipline' => 'Pump foil', 'Weight (kg)' => '0.29']],
            ['common(path(fields, "l"))', ['l' => [['Area' => '7', 'Discipline' => 'Freeride'], ['Area' => '8', 'Discipline' => 'Freeride']]], ['Discipline' => 'Freeride']],
            ['common(path(fields, "l"))', ['l' => [['Size' => 'S', 'Head (cm)' => '54'], ['Size' => 'M', 'Head (cm)' => '56']]], []],
            ['common(path(fields, "l"))', ['l' => [['A' => '1', 'B' => 'x'], ['A' => ' 1 ', 'C' => 'y'], 'junk', null, ['A' => 1, 'B' => 'z']]], ['A' => '1', 'C' => 'y']],
            ['common(path(fields, "l"))', ['l' => [['A' => '', 'B' => null, 'C' => ['x'], 'D' => ' '], ['A' => 'v', 'C' => 'c']]], ['A' => 'v', 'C' => 'c']],
            ['common(path(fields, "l"))', ['l' => ['Discipline' => 'Freeride', 'Area' => '7']], ['Discipline' => 'Freeride', 'Area' => '7']],
            ['common(path(fields, "l"))', ['l' => [['Z' => 'z', 'A' => 'a'], ['M' => 'm', 'Z' => 'z']]], ['Z' => 'z', 'A' => 'a', 'M' => 'm']],
            ['common(path(fields, "l"))', [], []], ['common(path(fields, "l"))', ['l' => []], []],
            ['pick(path(fields, "m"), ["B", "A", "Gone"])', ['m' => ['A' => '1', 'B' => '2', 'C' => '3']], ['B' => '2', 'A' => '1']],
            ['pick(path(fields, "m"), {"A": "Alpha", "B": "Beta", "Gone": "G"})', ['m' => ['B' => '2', 'A' => '1', 'C' => '3']], ['Alpha' => '1', 'Beta' => '2']],
            ['pick(path(fields, "m"), {"Finbox": "Fin box", "Fin box type": "Fin box", "Fin box": "Fin box"})', ['m' => ['Fin box' => 'US', 'Fin box type' => 'Slot']], ['Fin box' => 'Slot']],
            ['pick(path(fields, "m"), {"Finbox": "Fin box", "Fin box type": "Fin box"})', ['m' => ['Finbox' => '', 'Fin box type' => 'Slot']], ['Fin box' => 'Slot']],
            ['pick(path(fields, "m"), ["A", "B", "C", "D"])', ['m' => ['A' => null, 'B' => '', 'C' => ['x'], 'D' => 0]], ['D' => 0]],
            ['pick(path(fields, "m"), ["A"])', [], []],
            ['pick(common(path(fields, "t")), {"Struts": "Struts", "Size": "Size"})', ['t' => [['Struts' => '3', 'Size' => 'S'], ['Struts' => '3', 'Size' => 'M']]], ['Struts' => '3']],
            ['row(path(fields, "t"), "Area", "12")', ['t' => [['Area' => '11.0', 'W' => 'a'], ['Area' => '12.0', 'W' => 'b'], ['Area' => '12', 'W' => 'c']]], ['Area' => '12.0', 'W' => 'b']],
            ['row(path(fields, "t"), "Area", 12.0)', ['t' => [['Area' => '12']]], ['Area' => '12']],
            ['row(path(fields, "t"), ["Size", "Area (m²)"], path(fields, "s"))', ['t' => [['Size' => 'S'], ['Area (m²)' => 9, 'W' => 'x']], 's' => '9.00'], ['Area (m²)' => 9, 'W' => 'x']],
            ['row(path(fields, "t"), "Size", " m ")', ['t' => [['Size' => 'S'], ['Size' => ' M ']]], ['Size' => ' M ']],
            ['row(path(fields, "t"), "Size", "10")', ['t' => [['Size' => '10 cm'], ['Size' => 'XL']]], null],
            ['row(path(fields, "t"), ["A", "B"], "x")', ['t' => [['B' => 'x', 'A' => 'no'], ['A' => 'x']]], ['B' => 'x', 'A' => 'no']],
            ['row(path(fields, "t"), "A", "1")', ['t' => ['A' => '1', 'B' => '2']], ['A' => '1', 'B' => '2']],
            ['row(path(fields, "t"), "A", "1")', ['t' => [['A' => ['1']], 'junk', null]], null],
            ['row(path(fields, "t"), "A", null)', ['t' => [['A' => '1']]], null],
            ['row(path(fields, "t"), "A", "1")', [], null],
            ['merge(path(fields, "a"), path(fields, "b"), null)', ['a' => ['X' => '1', 'Y' => '2'], 'b' => ['Y' => '3', 'Z' => '4']], ['X' => '1', 'Y' => '3', 'Z' => '4']],
            ['merge(path(fields, "a"), path(fields, "b"))', ['b' => ['Y' => '3']], ['Y' => '3']],
            ['merge()', [], []],
            ['merge(common(path(fields, "t")), row(path(fields, "t"), "Area", "8"))', ['t' => [['Area' => '7', 'Struts' => '3'], ['Area' => '8.0', 'Struts' => '3']]], ['Struts' => '3', 'Area' => '8.0']],
            ['"it\'s" ~ \'it\\\'s\'', [], "it'sit's"],
        ];
    }

    #[DataProvider('brokenExpressions')]
    public function testWrapsSyntaxAndRuntimeErrors(string $expression): void
    {
        try {
            (new ExpressionEvaluator())->evaluate($expression, ['fields' => []]);
            self::fail('Expected expression evaluation to fail.');
        } catch (ExpressionEvaluationException $error) {
            self::assertSame($expression, $error->getExpression());
            self::assertInstanceOf(\Throwable::class, $error->getPrevious());
            self::assertStringContainsString($error->getPrevious()->getMessage(), $error->getMessage());
        }
    }

    public static function brokenExpressions(): array
    {
        return [
            ['fields['], ['unknown_function()'], ['path(fields, [])'], ['1 / 0'], ['regex("/[invalid/", "value")'], ['str([])'],
            ['lower([])'], ['upper([1])'], ['trim([])'], ['replace("a", [], "b")'], ['replace("a", null, "b")'],
            ['round("abc")'], ['round(true)'], ['round([1])'], ['round(1.5, 1.5)'], ['round(1.5, "x")'],
            ['join("a")'], ['join([[1]])'], ['join([1], [])'], ['count("a")'], ['count(5)'],
        ];
    }

    public function testHelperErrorMessagesAreClear(): void
    {
        $evaluator = new ExpressionEvaluator();
        foreach (['round("abc")' => 'wrap text in num()', 'join([[1]])' => 'join() expects', 'count("a")' => 'count() expects'] as $expression => $message) {
            try {
                $evaluator->evaluate($expression, ['fields' => []]);
                self::fail('Expected failure for ' . $expression);
            } catch (ExpressionEvaluationException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testAutomaticRowUsesDistinctKeyColumns(): void
    {
        $table = [
            ["\u{FEFF}EU" => '40', 'Size' => '5,5', 'Struts' => '3'],
            ["\u{FEFF}EU" => '41', 'Size' => '6', 'Struts' => '3'],
            ["\u{FEFF}EU" => '42', 'Size' => 'M', 'Struts' => '3'],
        ];
        self::assertSame('41', ExpressionEvaluator::row($table, '41.0')['EU']);
        self::assertSame('40', ExpressionEvaluator::row($table, '5.5')['EU'], 'comma decimals');
        self::assertSame('42', ExpressionEvaluator::row($table, ' m ')['EU'], 'text, trimmed, case-insensitive');
        self::assertNull(ExpressionEvaluator::row($table, '3'), 'a constant column is not a key');
        self::assertNull(ExpressionEvaluator::row($table, '99'));
        self::assertNull(ExpressionEvaluator::row($table, null));
        self::assertArrayHasKey('EU', ExpressionEvaluator::row($table, "\u{FEFF}40"), 'BOM stripped from keys');
        $ambiguous = [['A' => '1', 'B' => '2'], ['A' => '2', 'B' => '3']];
        self::assertNull(ExpressionEvaluator::row($ambiguous, '2'), 'two rows match');
        self::assertSame(['A' => '1', 'B' => '2'], ExpressionEvaluator::row($ambiguous, '1'));
        self::assertSame(['A' => '9'], ExpressionEvaluator::row([['A' => '9']], 9), 'single row');
        self::assertNull(ExpressionEvaluator::row([['A' => '9']], 8));
        self::assertSame(['Size' => 'S'], ExpressionEvaluator::row([['Size' => 'S'], 'junk', null], 's'));
        self::assertSame(['EU' => '40'], ExpressionEvaluator::row([["\u{FEFF}EU" => '40']], '40', ));
    }
}
