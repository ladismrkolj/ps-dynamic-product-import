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
}
