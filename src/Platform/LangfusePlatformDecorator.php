<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Platform;

use Lingoda\AiSdk\Audio\AudioOptionsInterface;
use Lingoda\AiSdk\Exception\ClientException;
use Lingoda\AiSdk\Exception\InvalidArgumentException;
use Lingoda\AiSdk\Exception\ModelNotFoundException;
use Lingoda\AiSdk\Exception\RuntimeException;
use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Provider\ProviderCollection;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Decorator that wraps the AI Platform to automatically trace all AI interactions to Langfuse.
 * This is the main integration point between the AI SDK and Langfuse tracing.
 */
final readonly class LangfusePlatformDecorator implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $decorated,
        private TraceManagerInterface $traceManager,
    ) {
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function ask(string|Prompt|Conversation $input, ?string $modelId = null, array $options = []): ResultInterface
    {
        $metadata = [];

        $model = $this->resolveModel($modelId);
        $metadata['provider'] = $model->getProvider()->getName();

        $traceName = $options['trace_name'] ?? 'ai-completion';
        Assert::string($traceName);
        unset($options['trace_name']);

        return $this->traceManager->trace(
            $traceName,
            $metadata,
            $input,
            fn () => $this->decorated->ask($input, $modelId, $options)
        );
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function textToSpeech(string $input, AudioOptionsInterface $options): BinaryResult
    {
        $result = $this->traceManager->trace(
            'text-to-speech',
            ['input_length' => mb_strlen($input)],
            $input,
            fn () => $this->decorated->textToSpeech($input, $options)
        );
        Assert::isInstanceOf($result, BinaryResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function textToSpeechStream(string $input, AudioOptionsInterface $options): StreamResult
    {
        $result = $this->traceManager->trace(
            'text-to-speech-stream',
            ['input_length' => mb_strlen($input)],
            $input,
            fn () => $this->decorated->textToSpeechStream($input, $options)
        );
        Assert::isInstanceOf($result, StreamResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function transcribeAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        $result = $this->traceManager->trace(
            'audio-transcription',
            ['audio_file' => basename($audioFilePath)],
            $audioFilePath,
            fn () => $this->decorated->transcribeAudio($audioFilePath, $options)
        );
        Assert::isInstanceOf($result, TextResult::class);

        return $result;
    }

    /**
     * @throws ModelNotFoundException|ClientException|RuntimeException|InvalidArgumentException|\Throwable
     */
    public function translateAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        $result = $this->traceManager->trace(
            'audio-translation',
            ['audio_file' => basename($audioFilePath)],
            $audioFilePath,
            fn () => $this->decorated->translateAudio($audioFilePath, $options)
        );
        Assert::isInstanceOf($result, TextResult::class);

        return $result;
    }

    public function getProvider(string $name): ProviderInterface
    {
        return $this->decorated->getProvider($name);
    }

    public function getAvailableProviders(): ProviderCollection
    {
        return $this->decorated->getAvailableProviders();
    }

    public function hasProvider(string $name): bool
    {
        return $this->decorated->hasProvider($name);
    }

    public function configureProviderDefaultModel(string $providerName, string $defaultModel): void
    {
        $this->decorated->configureProviderDefaultModel($providerName, $defaultModel);
    }

    public function resolveModel(?string $modelId): ModelInterface
    {
        return $this->decorated->resolveModel($modelId);
    }
}
