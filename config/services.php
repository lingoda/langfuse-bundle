<?php

declare(strict_types = 1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Dropsolid\LangFuse\Client;
use Dropsolid\LangFuse\DTO\ClientConfig;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\LangfuseBundle\Cache\PromptCache;
use Lingoda\LangfuseBundle\Client\PromptClient;
use Lingoda\LangfuseBundle\Client\TraceClient;
use Lingoda\LangfuseBundle\Command\CachePromptCommand;
use Lingoda\LangfuseBundle\Command\TestConnectionCommand;
use Lingoda\LangfuseBundle\Deserialization\PromptDeserializer;
use Lingoda\LangfuseBundle\Message\FlushLangfuseTraceHandler;
use Lingoda\LangfuseBundle\Naming\PromptIdentifier;
use Lingoda\LangfuseBundle\Platform\LangfusePlatformDecorator;
use Lingoda\LangfuseBundle\Prompt\PromptRegistry;
use Lingoda\LangfuseBundle\Prompt\PromptRegistryInterface;
use Lingoda\LangfuseBundle\Storage\PromptStorageRegistry;
use Lingoda\LangfuseBundle\Storage\StorageFactory;
use Lingoda\LangfuseBundle\Tracing\SyncTraceFlusher;
use Lingoda\LangfuseBundle\Tracing\TraceFlusherInterface;
use Lingoda\LangfuseBundle\Tracing\TraceManager;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->private()
    ;

    // === Core Client Configuration ===

    $services->set(ClientConfig::class)
        ->factory([ClientConfig::class, 'fromArray'])
        ->args([[
            'public_key' => param('lingoda_langfuse.connection.public_key'),
            'secret_key' => param('lingoda_langfuse.connection.secret_key'),
            'host' => param('lingoda_langfuse.connection.host'),
            'timeout' => param('lingoda_langfuse.connection.timeout'),
            'retry' => [
                'max_attempts' => param('lingoda_langfuse.connection.retry.max_attempts'),
                'delay' => param('lingoda_langfuse.connection.retry.delay'),
            ],
        ]])
    ;

    $services->set(Client::class)
        ->args([service(ClientConfig::class)])
    ;

    $services->set(TraceClient::class)
        ->args([
            service(Client::class),
            service('logger')->nullOnInvalid(),
        ])
        ->public()
        ->tag('monolog.logger', ['channel' => 'langfuse'])
    ;

    // === Trace Flushing Services ===

    // Synchronous flush service
    $services->set(SyncTraceFlusher::class)
        ->args([
            service(TraceClient::class),
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
            param('lingoda_langfuse.tracing.enabled'),
            param('lingoda_langfuse.tracing.sampling_rate'),
            param('kernel.environment'),
        ])
    ;

    $services->alias(TraceManagerInterface::class, TraceManager::class);

    // === Async Message Handler ===

    $services->set(FlushLangfuseTraceHandler::class)
        ->args([
            service(SyncTraceFlusher::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'langfuse'])
    ;

    // === Platform Decorator (Main Integration Point) ===

    $services->set(LangfusePlatformDecorator::class)
        ->decorate(PlatformInterface::class, null, 1)
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
    $services->set('lingoda_langfuse.storage_factory')
        ->class(StorageFactory::class)
        ->args([service('service_container')])
    ;

    $services->set(PromptStorageRegistry::class)
        ->factory([service('lingoda_langfuse.storage_factory'), 'create'])
        ->args([param('lingoda_langfuse.prompts.fallback')])
    ;

    $services->set(PromptDeserializer::class);

    $services->set(PromptClient::class)
        ->args([
            service(TraceClient::class),
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
        ->args([service(TraceClient::class)])
        ->tag('console.command')
    ;

    $services->set(CachePromptCommand::class)
        ->args([service(PromptRegistryInterface::class)])
        ->tag('console.command')
    ;
};
