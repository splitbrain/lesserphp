<?php
namespace LesserPHP\tests;

use Exception;
use LesserPHP\Lessc;
use PHPUnit\Framework\TestCase;

class ErrorHandlingTest extends TestCase {
    public function setUp(): void {
        $this->less = new Lessc();
    }

    public function compile() {
        $source = join("\n", func_get_args());
        return $this->less->compile($source);
    }

    public function testRequiredParametersMissing() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage(".parametric-mixin is undefined");
        $this->compile(
            '.parametric-mixin (@a, @b) { a: @a; b: @b; }',
            '.selector { .parametric-mixin(12px); }'
        );
    }

    public function testTooManyParameters() {
        $this->expectExceptionMessage(".parametric-mixin is undefined");
        $this->expectException(Exception::class);
        $this->compile(
            '.parametric-mixin (@a, @b) { a: @a; b: @b; }',
            '.selector { .parametric-mixin(12px, 13px, 14px); }'
        );
    }

    public function testRequiredArgumentsMissing() {
        $this->expectExceptionMessage("unrecognised input");
        $this->expectException(Exception::class);
        $this->compile('.selector { rule: e(); }');
    }

    public function testVariableMissing() {
        $this->expectExceptionMessage("variable @missing is undefined");
        $this->expectException(Exception::class);
        $this->compile('.selector { rule: @missing; }');
    }

    public function testMixinMissing() {
        $this->expectExceptionMessage(".missing-mixin is undefined");
        $this->expectException(Exception::class);
        $this->compile('.selector { .missing-mixin; }');
    }

    public function testGuardUnmatchedValue() {
        $this->expectExceptionMessage(".flipped is undefined");
        $this->expectException(Exception::class);
        $this->compile(
            '.flipped(@x) when (@x =< 10) { rule: value; }',
            '.selector { .flipped(12); }'
        );
    }

    public function testGuardUnmatchedType() {
        $this->expectExceptionMessage(".colors-only is undefined");
        $this->expectException(Exception::class);
        $this->compile(
            '.colors-only(@x) when (iscolor(@x)) { rule: value; }',
            '.selector { .colors-only("string value"); }'
        );
    }

    public function testMinNoArguments() {
        $this->expectExceptionMessage("expecting at least 1 arguments, got 0");
        $this->expectException(Exception::class);
        $this->compile(
            '.selector{ min: min(); }'
        );
    }

    public function testMaxNoArguments() {
        $this->expectExceptionMessage("expecting at least 1 arguments, got 0");
        $this->expectException(Exception::class);
        $this->compile(
            '.selector{ max: max(); }'
        );
    }

    public function testMaxIncompatibleTypes() {
        $this->expectExceptionMessage("Cannot convert % to px");
        $this->expectException(Exception::class);
        $this->compile(
            '.selector{ max: max( 10px, 5% ); }'
        );
    }

    public function testConvertIncompatibleTypes() {
        $this->expectExceptionMessage("Cannot convert px to s");
        $this->expectException(Exception::class);
        $this->compile(
            '.selector{ convert: convert( 10px, s ); }'
        );
    }
}
