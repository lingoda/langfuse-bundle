<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Command;

use Lingoda\LangfuseBundle\Command\CachePromptCommand;
use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Prompt\PromptRegistryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CachePromptCommandTest extends TestCase
{
    private PromptRegistryInterface&MockObject $mockRegistry;
    private CachePromptCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->mockRegistry = $this->createMock(PromptRegistryInterface::class);
        $this->command = new CachePromptCommand($this->mockRegistry);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandName(): void
    {
        self::assertEquals('langfuse:cache-prompt', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        self::assertEquals('Cache specific prompts from Langfuse to fallback storage', $this->command->getDescription());
    }

    public function testCommandWithoutPrompts(): void
    {
        $exitCode = $this->commandTester->execute([]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Langfuse Prompt Caching', $output);
        self::assertStringContainsString('No prompt names specified', $output);
        self::assertStringContainsString('--prompt=greeting', $output);
        self::assertStringContainsString('Available options:', $output);
    }

    public function testCommandWithSinglePrompt(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('test-prompt')
            ->willReturn(false)
        ;

        $this->mockRegistry
            ->expects(self::once())
            ->method('getRawPrompt')
            ->with('test-prompt', useCache: false)
            ->willReturn(['name' => 'test-prompt', 'content' => 'Hello'])
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['test-prompt']]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Processing: test-prompt', $output);
        self::assertStringContainsString('Cached: test-prompt', $output);
        self::assertStringContainsString('Cached: 1, Skipped: 0, Errors: 0', $output);
    }

    public function testCommandWithMultiplePrompts(): void
    {
        $this->mockRegistry
            ->method('has')
            ->willReturnCallback(fn ($name) => false)
        ;

        $this->mockRegistry
            ->method('getRawPrompt')
            ->willReturnCallback(fn ($name, $useCache) => ['name' => $name])
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['prompt1', 'prompt2']]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Processing: prompt1', $output);
        self::assertStringContainsString('Processing: prompt2', $output);
        self::assertStringContainsString('Cached: 2, Skipped: 0, Errors: 0', $output);
    }

    public function testCommandSkipsExistingPrompts(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('existing-prompt')
            ->willReturn(true)
        ;

        $this->mockRegistry
            ->expects(self::never())
            ->method('getRawPrompt')
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['existing-prompt']]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Processing: existing-prompt', $output);
        self::assertStringContainsString('Skipping (exists in storage, use --force to overwrite)', $output);
        self::assertStringContainsString('Cached: 0, Skipped: 1, Errors: 0', $output);
    }

    public function testCommandWithForceOption(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('existing-prompt')
            ->willReturn(true)
        ;

        $this->mockRegistry
            ->expects(self::once())
            ->method('getRawPrompt')
            ->with('existing-prompt', useCache: false)
            ->willReturn(['name' => 'existing-prompt'])
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['existing-prompt'], '--force' => true]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Processing: existing-prompt', $output);
        self::assertStringContainsString('Cached: existing-prompt', $output);
        self::assertStringContainsString('Cached: 1, Skipped: 0, Errors: 0', $output);
    }

    public function testCommandWithDryRunOption(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('test-prompt')
            ->willReturn(false)
        ;

        $this->mockRegistry
            ->expects(self::never())
            ->method('getRawPrompt')
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['test-prompt'], '--dry-run' => true]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Running in DRY-RUN mode', $output);
        self::assertStringContainsString('Processing: test-prompt', $output);
        self::assertStringContainsString('Would fetch and cache: test-prompt', $output);
        self::assertStringContainsString('Would cache 1 prompt(s)', $output);
    }

    public function testCommandHandlesLangfuseException(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('failing-prompt')
            ->willReturn(false)
        ;

        $this->mockRegistry
            ->expects(self::once())
            ->method('getRawPrompt')
            ->with('failing-prompt', useCache: false)
            ->willThrowException(new LangfuseException('Prompt not found'))
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['failing-prompt']]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Processing: failing-prompt', $output);
        self::assertStringContainsString('Error caching failing-prompt: Prompt not found', $output);
        self::assertStringContainsString('Cached: 0, Skipped: 0, Errors: 1', $output);
    }

    public function testCommandHandlesGeneralException(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->willThrowException(new \RuntimeException('General error'))
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['test-prompt']]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Unexpected error: General error', $output);
    }

    public function testCommandWithMixedResults(): void
    {
        $this->mockRegistry
            ->method('has')
            ->willReturnCallback(fn ($name) => $name === 'existing-prompt')
        ;

        $this->mockRegistry
            ->method('getRawPrompt')
            ->willReturnCallback(function ($name, $useCache) {
                if ($name === 'success-prompt') {
                    return ['name' => 'success-prompt'];
                }
                if ($name === 'error-prompt') {
                    throw new LangfuseException('Failed to fetch');
                }
                return null;
            })
        ;

        $exitCode = $this->commandTester->execute([
            '--prompt' => ['success-prompt', 'existing-prompt', 'error-prompt']
        ]);

        self::assertEquals(Command::FAILURE, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Cached: success-prompt', $output);
        self::assertStringContainsString('Skipping (exists in storage', $output);
        self::assertStringContainsString('Error caching error-prompt', $output);
        self::assertStringContainsString('Cached: 1, Skipped: 1, Errors: 1', $output);
    }

    public function testCommandConfiguration(): void
    {
        $definition = $this->command->getDefinition();

        self::assertTrue($definition->hasOption('dry-run'));
        self::assertTrue($definition->hasOption('force'));
        self::assertTrue($definition->hasOption('prompt'));

        $promptOption = $definition->getOption('prompt');
        self::assertTrue($promptOption->isValueRequired());
        self::assertTrue($promptOption->isArray());
        self::assertEquals('p', $promptOption->getShortcut());
    }

    public function testCommandOutputSections(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->willReturn(false)
        ;

        $this->mockRegistry
            ->expects(self::once())
            ->method('getRawPrompt')
            ->willReturn(['name' => 'test'])
        ;

        $exitCode = $this->commandTester->execute(['--prompt' => ['test']]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Langfuse Prompt Caching', $output);
        self::assertStringContainsString('Configuration', $output);
        self::assertStringContainsString('Fallback storage:', $output);
        self::assertStringContainsString('Caching specific prompts', $output);
        self::assertStringContainsString('Summary', $output);
    }

    public function testCommandWithEmptyPromptArray(): void
    {
        $exitCode = $this->commandTester->execute(['--prompt' => []]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No prompt names specified', $output);
    }

    public function testCommandDryRunWithExistingPrompt(): void
    {
        $this->mockRegistry
            ->expects(self::once())
            ->method('has')
            ->with('existing-prompt')
            ->willReturn(true)
        ;

        $this->mockRegistry
            ->expects(self::never())
            ->method('getRawPrompt')
        ;

        $exitCode = $this->commandTester->execute([
            '--prompt' => ['existing-prompt'],
            '--dry-run' => true
        ]);

        self::assertEquals(Command::SUCCESS, $exitCode);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Running in DRY-RUN mode', $output);
        self::assertStringContainsString('Skipping (exists in storage', $output);
        self::assertStringContainsString('Would cache 0 prompt(s)', $output);
    }
}
