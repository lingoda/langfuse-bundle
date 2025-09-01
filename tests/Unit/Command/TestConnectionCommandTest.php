<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Command;

use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\Command\TestConnectionCommand;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TestConnectionCommandTest extends TestCase
{
    private TraceClient&MockObject $mockClient;
    private TestConnectionCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(TraceClient::class);
        $this->command = new TestConnectionCommand($this->mockClient);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandName(): void
    {
        self::assertEquals('langfuse:test-connection', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        self::assertEquals('Test connection to Langfuse API', $this->command->getDescription());
    }

    public function testSuccessfulConnection(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willReturn(true)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Testing Langfuse Connection', $output);
        self::assertStringContainsString('Attempting to connect to Langfuse API...', $output);
        self::assertStringContainsString('Successfully connected to Langfuse API!', $output);
    }

    public function testFailedConnection(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willReturn(false)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Testing Langfuse Connection', $output);
        self::assertStringContainsString('Attempting to connect to Langfuse API...', $output);
        self::assertStringContainsString('Failed to connect to Langfuse API', $output);
        self::assertStringNotContainsString('Successfully connected', $output);
    }

    public function testConnectionThrowsException(): void
    {
        $exception = new \RuntimeException('Connection timeout');

        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willThrowException($exception)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Testing Langfuse Connection', $output);
        self::assertStringContainsString('Connection failed: Connection timeout', $output);
        self::assertStringNotContainsString('Successfully connected', $output);
    }

    public function testConnectionExceptionHandling(): void
    {
        $exception = new \RuntimeException('Connection timeout');

        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willThrowException($exception)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Connection failed: Connection timeout', $output);
        self::assertStringContainsString('Testing Langfuse Connection', $output);
    }

    public function testConnectionWithDifferentExceptionTypes(): void
    {
        $exception = new \InvalidArgumentException('Invalid API key');

        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willThrowException($exception)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Connection failed: Invalid API key', $output);
    }

    public function testCommandOutput(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willReturn(true)
        ;

        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();

        // Verify output structure
        self::assertStringContainsString('Testing Langfuse Connection', $output);
        self::assertStringContainsString('Attempting to connect', $output);
        self::assertStringContainsString('Successfully connected', $output);
    }

    public function testCommandProducesOutput(): void
    {
        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willReturn(true)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        // Command should produce output
        $output = $this->commandTester->getDisplay();
        self::assertNotEmpty($output);
        self::assertStringContainsString('Testing Langfuse Connection', $output);
    }

    public function testCommandWithEmptyExceptionMessage(): void
    {
        $exception = new \RuntimeException('');

        $this->mockClient
            ->expects(self::once())
            ->method('testConnection')
            ->willThrowException($exception)
        ;

        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Connection failed:', $output);
    }
}
