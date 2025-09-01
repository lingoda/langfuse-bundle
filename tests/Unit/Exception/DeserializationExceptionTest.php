<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Exception;

use Lingoda\LangfuseBundle\Exception\DeserializationException;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use PHPUnit\Framework\TestCase;

final class DeserializationExceptionTest extends TestCase
{
    public function testExceptionExtendsLangfuseException(): void
    {
        $exception = new DeserializationException('Test message');

        self::assertInstanceOf(LangfuseException::class, $exception);
    }

    public function testExceptionWithMessage(): void
    {
        $message = 'Failed to deserialize prompt data';
        $exception = new DeserializationException($message);

        self::assertEquals($message, $exception->getMessage());
    }

    public function testExceptionWithMessageAndCode(): void
    {
        $message = 'Invalid JSON format';
        $code = 400;
        $exception = new DeserializationException($message, $code);

        self::assertEquals($message, $exception->getMessage());
        self::assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \JsonException('Malformed JSON');
        $exception = new DeserializationException('JSON parsing failed', 0, $previous);

        self::assertEquals('JSON parsing failed', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
