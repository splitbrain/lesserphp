<?php

namespace LesserPHP\tests;

use LesserPHP\Lessc;
use PHPUnit\Framework\TestCase;

class InputTest extends TestCase
{
    const TESTDATA = __DIR__ . '/test-data';

    /**
     * Provide the test data from all sets
     */
    public function provideTestData()
    {
        $sets = glob(self::TESTDATA . '/less/*', GLOB_ONLYDIR);
        foreach ($sets as $set) {
            if (preg_match('/\.disabled$/', $set)) {
                continue; // skip disabled sets
            }
            $setName = basename($set);

            if($setName == 'data') continue; // this is not a test set but additional import data

            $tests = glob($set . '/*.less');
            foreach ($tests as $testFile) {
                [$testName] = explode('.', basename($testFile));
                yield [$setName, $testName];
            }
        }
    }

    /**
     * @dataProvider provideTestData
     */
    public function testInputOutput($set, $test)
    {
        $name = "$set/$test";
        $inputDir = self::TESTDATA . '/less/' . $set;
        $inputBase = $inputDir . '/' . $test;
        $outputFile = self::TESTDATA . '/css/' . $set . '/' . $test . '.css';

        if (is_file($inputBase . '.skip.less')) {
            $this->markTestIncomplete("$name: needs work to pass");
        }

        if (!is_file($outputFile)) {
            $this->markTestSkipped("$name: output file missing");
        }

        $lessc = new Lessc();
        $lessc->importDir = [
            $inputDir . '/imports',
            $inputDir,
            self::TESTDATA . '/less/data',
        ];

        if (is_file($inputBase . '.json')) {
            $lessc->setVariables(json_decode(file_get_contents($inputBase . '.json'), true));
        }

        $input = file_get_contents($inputBase . '.less');
        $output = file_get_contents($outputFile);

        $this->assertEquals($output, $lessc->compile($input), "Failed test $name");
    }
}
