<?php

/**
 * PHPUnit 9 does not ship PHPUnit\Framework\Attributes\*.
 * Provide no-op stubs so test files that import attributes still load when
 * Composer resolves phpunit ^9.6 on PHP 7.4/8.0. PHPUnit 10+ ships the real
 * attribute classes; allow Composer autoload to find them before stubbing.
 *
 * On PHP 7.4, #[Attr] is a line comment, so discovery still requires test*
 * method names (or docblock metadata). Keep test* prefixes alongside attributes.
 */

namespace PHPUnit\Framework\Attributes;

// Autoload=true so PHPUnit 10+ attribute classes win over these stubs.
if (!class_exists(Test::class)) {
    final class Test
    {
    }
}

if (!class_exists(Group::class)) {
    final class Group
    {
        /** @var mixed */
        public $name;

        /** @param mixed $name */
        public function __construct($name = null)
        {
            $this->name = $name;
        }
    }
}

if (!class_exists(RunInSeparateProcess::class)) {
    final class RunInSeparateProcess
    {
    }
}

if (!class_exists(DataProvider::class)) {
    final class DataProvider
    {
        /** @var mixed */
        public $name;

        /** @param mixed $name */
        public function __construct($name = null)
        {
            $this->name = $name;
        }
    }
}
