<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Exception;

use Lingoda\LangfuseBundle\Exception\LangfuseException;
use PHPUnit\Framework\TestCase;

final class LangfuseExceptionTest extends TestCase
{
    public function testExceptionWithMessage(): void
    {
        $message = 'Langfuse operation failed';
        $exception = new LangfuseException($message);

        self::assertEquals($message, $exception->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message = 'HTTP error';
        $code = 404;
        $exception = new LangfuseException($message, $code);

        self::assertEquals($message, $exception->getMessage());
        self::assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Original error');
        $exception = new LangfuseException('Wrapped error', 0, $previous);

        self::assertEquals('Wrapped error', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionWithAllParameters(): void
    {
        $message = 'Complete error';
        $code = 500;
        $previous = new \InvalidArgumentException('Validation failed');

        $exception = new LangfuseException($message, $code, $previous);

        self::assertEquals($message, $exception->getMessage());
        self::assertEquals($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
