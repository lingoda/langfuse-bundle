<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case with improved type hints for mocks.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * Create a mock object with proper type hints.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return MockObject&T
     */
    protected function createTypedMock(string $className): MockObject
    {
        return $this->createMock($className);
    }

    /**
     * Create a partial mock with proper type hints.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string> $methods
     * @return MockObject&T
     */
    protected function createTypedPartialMock(string $className, array $methods): MockObject
    {
        return $this->createPartialMock($className, $methods);
    }

    /**
     * Create a configured mock with proper type hints.
     *
     * @template T of object
     * @param class-string<T> $className
     * @param array<string> $methods
     * @return MockObject&T
     */
    protected function createTypedConfiguredMock(string $className, array $methods = []): MockObject
    {
        return $this->getMockBuilder($className)
            ->onlyMethods($methods)
            ->getMock()
        ;
    }
}
