<?php

namespace LesserPHP\tests;

use LesserPHP\Lessc;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    public function setUp(): void
    {
        $this->less = new Lessc();
        $this->less->importDir = [__DIR__ . '/test-data/less/lesserphp/imports'];
    }

    public function testPreserveComments()
    {
        $input = <<<EOD
// what is going on?

/** what the heck **/

/**

Here is a block comment

**/


// this is a comment

/*hello*/div /*yeah*/ { //surew
    border: 1px solid red; // world
    /* comment above the first occurrence of a duplicated rule */
    color: url('http://mage-page.com');
    string: "hello /* this is not a comment */";
    world: "// neither is this";
    /* comment above the second occurrence of a duplicated rule */
    color: url('http://mage-page.com');
    string: 'hello /* this is not a comment */' /*what if this is a comment */;
    world: '// neither is this' // hell world;
    ;
    /* duplicate comments are retained */
    /* duplicate comments are retained */
    what-ever: 100px;
    background: url(/*this is not a comment?*/); // uhh what happens here
}
EOD;


        $outputWithComments = <<<EOD
/** what the heck **/
/**

Here is a block comment

**/
/*hello*/
/*yeah*/
div /*yeah*/ {
  border: 1px solid red;
  /* comment above the first occurrence of a duplicated rule */
  /* comment above the second occurrence of a duplicated rule */
  color: url('http://mage-page.com');
  string: "hello /* this is not a comment */";
  world: "// neither is this";
  /*what if this is a comment */
  string: 'hello /* this is not a comment */';
  world: '// neither is this';
  /* duplicate comments are retained */
  /* duplicate comments are retained */
  what-ever: 100px;
  /*this is not a comment?*/
  background: url();
}
EOD;

        $outputWithoutComments = <<<EOD
div {
  border: 1px solid red;
  color: url('http://mage-page.com');
  string: "hello /* this is not a comment */";
  world: "// neither is this";
  string: 'hello /* this is not a comment */';
  world: '// neither is this';
  what-ever: 100px;
  background: url(/*this is not a comment?*/);
}
EOD;

        $this->assertEquals(
            trim($outputWithoutComments),
            trim($this->less->compile($input))
        );
        $this->less->setPreserveComments(true);
        $this->assertEquals(
            trim($outputWithComments),
            trim($this->less->compile($input)),
        );
    }

    public function testInjectVars()
    {
        $this->less->setVariables(
            [
                'color' => 'red',
                'base' => '960px'
            ]
        );

        $out = $this->less->compile('.magic { color: @color;  width: @base - 200; }');

        $this->assertEquals(
            trim("
.magic {
  color: red;
  width: 760px;
}
            "),
            trim($out)
        );
    }

    public function testDisableImport()
    {
        $this->less->importDisabled = true;
        $this->assertEquals(
            '/* import disabled */',
            trim($this->less->compile("@import 'file3';"))
        );
    }

    public function testUserFunction()
    {
        $this->less->registerFunction('add-two', function ($list) {
            [$a, $b] = $list[2];
            return $a[1] + $b[1];
        });

        $this->assertEquals(
            'result: 30;',
            trim($this->less->compile('result: add-two(10, 20);'))
        );

        return $this->less;
    }

    /**
     * @depends testUserFunction
     */
    public function testUnregisterFunction($less)
    {
        $less->unregisterFunction('add-two');

        $this->assertEquals(
            'result: add-two(10,20);',
            trim($this->less->compile('result: add-two(10, 20);'))
        );
    }


    public function testFormatters()
    {
        $src = "
            div, pre {
                color: blue;
                span, .big, hello.world {
                    height: 20px;
                    color:#ffffff + #000;
                }
            }";

        $this->less->setFormatter('compressed');
        $this->assertEquals(
            trim("
div,pre{color:blue;}div span,div .big,div hello.world,pre span,pre .big,pre hello.world{height:20px;color:#fff;}
            "),
            trim($this->less->compile($src))
        );

        // TODO: fix the output order of tags
        $this->less->setFormatter('lessjs');
        $this->assertEquals(
            trim("
div,
pre {
  color: blue;
}
div span,
div .big,
div hello.world,
pre span,
pre .big,
pre hello.world {
  height: 20px;
  color: #ffffff;
}
            "),
            trim($this->less->compile($src))
        );

        $this->less->setFormatter('classic');
        $this->assertEquals(
            trim("
div, pre { color:blue; }
div span, div .big, div hello.world, pre span, pre .big, pre hello.world {
  height:20px;
  color:#ffffff;
}
            "),
            trim($this->less->compile($src))
        );
    }
}
