# LesserPHP Test Suite

LesserPHP uses [phpunit](https://github.com/sebastianbergmann/phpunit/) for its tests

## InputTest.php

This iterates through all the `.less` files in `test-data/less/*/*.less`, compiles them and compares the result with the respective file in `test-data/css/*/*.css`.

Most of the tests are taken from the [less.js](https://github.com/less/less.js/tree/master/packages/test-data) test suite as of January 2024 (release 4.2.0+). They are thus licensed under the [Apache License 2.0](https://github.com/less/less.js/blob/master/LICENSE).

The exception are all files unter `test-data/lesserphp/` which have been written for LessPHP and LesserPHP in the past and thus fall under same license as lesserphp itself.

**LesserPHP is not able to pass all upstream tests!** 

Input files ending in `.skip.less` are marked as a skipped test. These tests still need to be manually checked and either be adjusted to the capabilities of LesserPHP or LesserPHP needs to be improved to pass the test. 

Files and directories ending in `.isabled` are completely ignored. These tests are probably not applicable to LesserPHP or need major changes.

## ApiTest.php

Tests the behavior of LesserPHP's public API methods.

## ErrorHandlingTest.php

Tests that lessphp throws appropriate errors when given invalid LESS as input.

