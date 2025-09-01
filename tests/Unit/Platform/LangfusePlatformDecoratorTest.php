<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Tests\Unit\Platform;

use Lingoda\AiSdk\Audio\AudioOptionsInterface;
use Lingoda\AiSdk\Exception\ModelNotFoundException;
use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Provider\ProviderCollection;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LangfusePlatformDecoratorTest extends TestCase
{
    private PlatformInterface&MockObject $mockPlatform;
    private TraceManagerInterface&MockObject $mockTraceManager;
    private LangfusePlatformDecorator $decorator;

    protected function setUp(): void
    {
        $this->mockPlatform = $this->createMock(PlatformInterface::class);
        $this->mockTraceManager = $this->createMock(TraceManagerInterface::class);

        $this->decorator = new LangfusePlatformDecorator(
            $this->mockPlatform,
            $this->mockTraceManager
        );
    }

    public function testAskWithStringInput(): void
    {
        $input = "What is the weather like?";
        $modelId = 'gpt-4';
        $options = ['temperature' => 0.7];
        $result = $this->createMock(TextResult::class);

        $mockModel = $this->createMock(ModelInterface::class);
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider->method('getName')->willReturn('openai');
        $mockModel->method('getProvider')->willReturn($mockProvider);

        $this->mockPlatform
            ->expects(self::once())
            ->method('resolveModel')
            ->with($modelId)
            ->willReturn($mockModel)
        ;

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'ai-completion',
                ['provider' => 'openai'],
                $input,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($input, $modelId, $options)
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->ask($input, $modelId, $options);

        self::assertSame($result, $actualResult);
    }

    public function testAskWithPromptInput(): void
    {
        $prompt = $this->createMock(Prompt::class);
        $result = $this->createMock(TextResult::class);

        $mockModel = $this->createMock(ModelInterface::class);
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider->method('getName')->willReturn('anthropic');
        $mockModel->method('getProvider')->willReturn($mockProvider);

        $this->mockPlatform
            ->expects(self::once())
            ->method('resolveModel')
            ->with(null)
            ->willReturn($mockModel)
        ;

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'ai-completion',
                ['provider' => 'anthropic'],
                $prompt,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($prompt, null, [])
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->ask($prompt);

        self::assertSame($result, $actualResult);
    }

    public function testAskWithConversationInput(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $modelId = 'claude-3';
        $result = $this->createMock(TextResult::class);

        $mockModel = $this->createMock(ModelInterface::class);
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider->method('getName')->willReturn('anthropic');
        $mockModel->method('getProvider')->willReturn($mockProvider);

        $this->mockPlatform->method('resolveModel')->willReturn($mockModel);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'ai-completion',
                ['provider' => 'anthropic'],
                $conversation,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($conversation, $modelId, [])
            ->willReturn($result)
        ;

        $this->decorator->ask($conversation, $modelId);
    }

    public function testAskWithCustomTraceName(): void
    {
        $input = "Custom trace";
        $options = ['trace_name' => 'custom-operation', 'temperature' => 0.5];
        $result = $this->createMock(TextResult::class);

        $mockModel = $this->createMock(ModelInterface::class);
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider->method('getName')->willReturn('openai');
        $mockModel->method('getProvider')->willReturn($mockProvider);

        $this->mockPlatform->method('resolveModel')->willReturn($mockModel);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'custom-operation',
                ['provider' => 'openai'],
                $input,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        // Should remove trace_name from options passed to platform
        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($input, null, ['temperature' => 0.5])
            ->willReturn($result)
        ;

        $this->decorator->ask($input, null, $options);
    }

    public function testTextToSpeech(): void
    {
        $input = "Hello, this is a test message for text-to-speech";
        $options = $this->createMock(AudioOptionsInterface::class);
        $result = $this->createMock(BinaryResult::class);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'text-to-speech',
                ['input_length' => mb_strlen($input)],
                $input,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('textToSpeech')
            ->with($input, $options)
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->textToSpeech($input, $options);

        self::assertSame($result, $actualResult);
    }

    public function testTextToSpeechStream(): void
    {
        $input = "Stream this text";
        $options = $this->createMock(AudioOptionsInterface::class);
        $result = $this->createMock(StreamResult::class);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'text-to-speech-stream',
                ['input_length' => mb_strlen($input)],
                $input,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('textToSpeechStream')
            ->with($input, $options)
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->textToSpeechStream($input, $options);

        self::assertSame($result, $actualResult);
    }

    public function testTranscribeAudio(): void
    {
        $audioFilePath = '/path/to/audio/file.mp3';
        $options = $this->createMock(AudioOptionsInterface::class);
        $result = $this->createMock(TextResult::class);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'audio-transcription',
                ['audio_file' => 'file.mp3'],
                $audioFilePath,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('transcribeAudio')
            ->with($audioFilePath, $options)
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->transcribeAudio($audioFilePath, $options);

        self::assertSame($result, $actualResult);
    }

    public function testTranslateAudio(): void
    {
        $audioFilePath = '/uploads/recordings/spanish_audio.wav';
        $options = $this->createMock(AudioOptionsInterface::class);
        $result = $this->createMock(TextResult::class);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'audio-translation',
                ['audio_file' => 'spanish_audio.wav'],
                $audioFilePath,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('translateAudio')
            ->with($audioFilePath, $options)
            ->willReturn($result)
        ;

        $actualResult = $this->decorator->translateAudio($audioFilePath, $options);

        self::assertSame($result, $actualResult);
    }

    public function testGetProvider(): void
    {
        $providerName = 'openai';
        $provider = $this->createMock(ProviderInterface::class);

        $this->mockPlatform
            ->expects(self::once())
            ->method('getProvider')
            ->with($providerName)
            ->willReturn($provider)
        ;

        $result = $this->decorator->getProvider($providerName);

        self::assertSame($provider, $result);
    }

    public function testGetAvailableProviders(): void
    {
        $providers = $this->createMock(ProviderCollection::class);

        $this->mockPlatform
            ->expects(self::once())
            ->method('getAvailableProviders')
            ->willReturn($providers)
        ;

        $result = $this->decorator->getAvailableProviders();

        self::assertSame($providers, $result);
    }

    public function testHasProvider(): void
    {
        $providerName = 'anthropic';

        $this->mockPlatform
            ->expects(self::once())
            ->method('hasProvider')
            ->with($providerName)
            ->willReturn(true)
        ;

        $result = $this->decorator->hasProvider($providerName);

        self::assertTrue($result);
    }

    public function testConfigureProviderDefaultModel(): void
    {
        $providerName = 'openai';
        $defaultModel = 'gpt-4-turbo';

        $this->mockPlatform
            ->expects(self::once())
            ->method('configureProviderDefaultModel')
            ->with($providerName, $defaultModel)
        ;

        $this->decorator->configureProviderDefaultModel($providerName, $defaultModel);
    }

    public function testResolveModel(): void
    {
        $modelId = 'gpt-3.5-turbo';
        $model = $this->createMock(ModelInterface::class);

        $this->mockPlatform
            ->expects(self::once())
            ->method('resolveModel')
            ->with($modelId)
            ->willReturn($model)
        ;

        $result = $this->decorator->resolveModel($modelId);

        self::assertSame($model, $result);
    }

    public function testAskThrowsModelNotFoundException(): void
    {
        $input = "Test input";
        $modelId = 'non-existent-model';

        $this->mockPlatform
            ->expects(self::once())
            ->method('resolveModel')
            ->with($modelId)
            ->willThrowException(new ModelNotFoundException("Model $modelId not found"))
        ;

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model $modelId not found");

        $this->decorator->ask($input, $modelId);
    }

    public function testTextToSpeechWithUnicodeInput(): void
    {
        $input = "Hola, ¿cómo estás? 你好吗？🌍";
        $options = $this->createMock(AudioOptionsInterface::class);
        $result = $this->createMock(BinaryResult::class);

        $this->mockTraceManager
            ->expects(self::once())
            ->method('trace')
            ->with(
                'text-to-speech',
                ['input_length' => mb_strlen($input)], // Should handle unicode properly
                $input,
                self::isInstanceOf(\Closure::class)
            )
            ->willReturnCallback(fn ($name, $metadata, $input, $callable) => $callable())
        ;

        $this->mockPlatform
            ->expects(self::once())
            ->method('textToSpeech')
            ->with($input, $options)
            ->willReturn($result)
        ;

        $this->decorator->textToSpeech($input, $options);
    }
}
