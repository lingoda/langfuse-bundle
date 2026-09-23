<?php

declare(strict_types = 1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\LangfuseBundle\Cache\PromptCache;
use Lingoda\LangfuseBundle\Client\LangfuseConnection;
use Lingoda\LangfuseBundle\Client\OtlpTraceExporter;
use Lingoda\LangfuseBundle\Client\PromptClient;
use Lingoda\LangfuseBundle\Command\CachePromptCommand;
use Lingoda\LangfuseBundle\Command\TestConnectionCommand;
use Lingoda\LangfuseBundle\Deserialization\PromptDeserializer;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTraceHandler;
use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Lingoda\LangfuseBundle\Platform\DecisionPlatformDecorator;
use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Lingoda\LangfuseBundle\Prompt\PromptRegistry;
use Lingoda\LangfuseBundle\Prompt\PromptRegistryInterface;
use Lingoda\LangfuseBundle\Storage\PromptStorageRegistry;
use Lingoda\LangfuseBundle\Storage\StorageFactory;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use Lingoda\LangfuseBundle\Tracing\TraceFlusherInterface;
use Lingoda\LangfuseBundle\Tracing\TraceManager;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
    ;

    // === Core Client Configuration ===

    $services->set(LangfuseConnection::class)
        ->args([
            param('lingoda_langfuse.connection.host'),
            param('lingoda_langfuse.connection.public_key'),
            param('lingoda_langfuse.connection.secret_key'),
            param('lingoda_langfuse.connection.timeout'),
        ])
    ;

    // Langfuse v4 ingestion: OpenTelemetry over HTTP/JSON
    $services->set(OtlpTraceExporter::class)
        ->args([
            service(LangfuseConnection::class),
            service('http_client')->nullOnInvalid(),
            param('lingoda_langfuse.tracing.export_timeout'),
        ])
    ;

    // === Trace Flushing Services ===

    // Synchronous flush service
    $services->set(SyncTraceFlusher::class)
        ->args([
            service(OtlpTraceExporter::class),
            service('logger')->nullOnInvalid(),
        ])
        ->public()
        ->tag('monolog.logger', ['channel' => 'langfuse'])
    ;

    $services->alias(TraceFlusherInterface::class, SyncTraceFlusher::class);

    // === Trace Manager ===

    $services->set(TraceManager::class)
        ->args([
            service(TraceFlusherInterface::class),
            service('clock'),
            param('kernel.environment'),
            param('lingoda_langfuse.tracing.enabled'),
            param('lingoda_langfuse.tracing.sampling_rate'),
        ])
    ;

    $services->alias(TraceManagerInterface::class, TraceManager::class);

    // === Async Message Handler ===

    // Tagged instead of #[AsMessageHandler], so apps without symfony/messenger can boot the bundle
    $services->set(FlushLangfuseTraceHandler::class)
        ->args([
            service(SyncTraceFlusher::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'langfuse'])
        ->tag('messenger.message_handler')
    ;

    // === Platform Decorator (Main Integration Point) ===

    $services->set(LangfusePlatformDecorator::class)
        ->decorate(PlatformInterface::class, null, 1)
        ->args([
            service('.inner'),
            service(TraceManagerInterface::class),
        ])
    ;

    // Decisions (TypeSafe Jev): ai-bundle registers the platform only when providers.typesafe has an api_key
    $services->set(DecisionPlatformDecorator::class)
        ->decorate(DecisionPlatformInterface::class, null, 1, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([
            service('.inner'),
            service(TraceManagerInterface::class),
        ])
    ;

    // === Prompt Management ===

    $services->set(PromptIdentifier::class);

    $services->set(PromptCache::class)
        ->args([
            expr('service(parameter("lingoda_langfuse.prompts.caching.service"))'),
            param('lingoda_langfuse.prompts.caching.ttl'),
            service(PromptIdentifier::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'langfuse'])
    ;

    // Storage factory for creating appropriate storage implementations
    $services->set(StorageFactory::class)
        ->args([]) // No arguments by default, will be overridden if service is configured
    ;

    $services->set(PromptStorageRegistry::class)
        ->factory([service(StorageFactory::class), 'create'])
        ->args([param('lingoda_langfuse.prompts.fallback')])
        ->lazy(true)
    ;

    $services->set(PromptDeserializer::class);

    $services->set(PromptClient::class)
        ->args([
            service(LangfuseConnection::class),
            service('http_client')->nullOnInvalid(),
        ])
    ;

    $services->set(PromptRegistry::class)
        ->args([
            service(PromptClient::class),
            service(PromptCache::class),
            service(PromptStorageRegistry::class),
            service(PromptDeserializer::class),
        ])
    ;

    $services->alias(PromptRegistryInterface::class, PromptRegistry::class)
        ->public()
    ;

    // === Console Commands ===

    $services->set(TestConnectionCommand::class)
        ->args([service(LangfuseConnection::class), service('http_client')->nullOnInvalid()])
        ->tag('console.command')
    ;

    $services->set(CachePromptCommand::class)
        ->args([service(PromptRegistryInterface::class)])
        ->tag('console.command')
    ;
};
