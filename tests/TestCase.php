<?php

declare(strict_types=1);

namespace Juphyter\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The base case for juphyter's PHP tests.
 *
 * Helpers that every layer needs belong here. The stream capture the console
 * tests use is the `captured()` function in tests/Pest.php, so that there is
 * one way to do it rather than two.
 */
abstract class TestCase extends BaseTestCase {}
