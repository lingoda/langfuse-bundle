# Lingoda Langfuse Bundle

A Symfony bundle for integrating with Langfuse, providing AI operation tracing, prompt management, and observability for AI applications built with the Lingoda AI SDK.

## Features

- 🔍 **Automatic AI Tracing**: Traces all AI operations (completions, TTS, STT, translations)
- ⚡ **Async/Sync Flushing**: Configurable sync or async trace processing via Symfony Messenger
- 📝 **Prompt Management**: Fetch, cache, and manage prompts with intelligent cascade (Cache → API → Storage)
- 💰 **Usage Metrics**: Automatic token counting and cost tracking with proper generation support
- 🗄️ **Flexible Storage**: Path-based or Flysystem storage for prompt fallbacks
- 🔧 **Console Commands**: Connection testing and prompt management tools

## Requirements

- PHP 8.4 or higher
- Symfony 7.4 or 8.0+
- Lingoda AI Bundle 2.0+ and Lingoda AI SDK 2.1+ (the bundle decorates the SDK's platforms)
- Langfuse Cloud or a self-hosted Langfuse on v4 (traces are sent as OpenTelemetry, see [Langfuse v4](#langfuse-v4-ingestion))

## Installation

1. Install the bundle via Composer:

```bash
composer require lingoda/langfuse-bundle
```

2. Add the bundle to `config/bundles.php`:

```php
return [
    // ...
    Lingoda\LangfuseBundle\LingodaLangfuseBundle::class => ['all' => true],
];
```

3. Configure the bundle in `config/packages/lingoda_langfuse.yaml`:

```yaml
lingoda_langfuse:
    connection:
        public_key: '%env(LANGFUSE_PUBLIC_KEY)%'
        secret_key: '%env(LANGFUSE_SECRET_KEY)%'
        host: '%env(default:LANGFUSE_HOST:https://cloud.langfuse.com)%'
        timeout: 30
        retry:
            max_attempts: 3
            delay: 1000

    tracing:
        enabled: true
        sampling_rate: 1.0
        async_flush:
            enabled: false
            message_bus: 'messenger.bus.default'

    prompts:
        caching:
            enabled: false
            ttl: 3600
            service: 'cache.app'
        fallback:
            enabled: true
            storage:
                path: '%kernel.project_dir%/var/prompts'
```

## Core Features

### Automatic AI Operation Tracing

The bundle automatically traces all AI operations by decorating the Lingoda AI SDK's `PlatformInterface`:

```php
use Lingoda\AiSdk\PlatformInterface;

class ContentService
{
    public function __construct(
        private PlatformInterface $platform
    ) {}

    public function generateContent(string $prompt): string
    {
        // This call is automatically traced to Langfuse with proper generation and usage metrics
        $result = $this->platform->ask($prompt, 'gpt-4');

        return $result->getContent();
    }

    public function generateAudio(string $text): string
    {
        // TTS operations are also traced
        $audio = $this->platform->textToSpeech($text);

        return $audio->getContent();
    }
}
```

**Automatic trace data includes:**
- Operation type (ai-completion, text-to-speech, etc.)
- Model information and provider resolution
- Input/output content and metadata
- Duration and timing information
- **Usage metrics (prompt tokens, completion tokens, total tokens)**
- **Proper Langfuse generation structure for cost tracking**
- Error handling and status tracking

Every platform an app can inject is traced: the main `PlatformInterface`, and the single-provider platforms ai-bundle registers (`$openaiPlatform`, `$bedrockPlatform`, ...). Attachments appear in the input as `{mime, size}` only; their bytes and filenames never reach Langfuse.

#### Keeping personal data out of Langfuse

Pass `trace_content: false` to keep a call's content out of Langfuse:

```php
$result = $this->platform->ask($conversation, 'amazon.nova-2-lite-v1:0', ['trace_content' => false]);
```

The trace still records the name, model, provider, usage, duration and status. The input is recorded as `{type: redacted}`, there is no output, and an error is recorded as its exception class only (provider errors can quote the input). Nothing sensitive reaches the Messenger message or the failure transport either.

#### Linking generations to Langfuse prompts

```php
$conversation = $this->prompts->getCompiled('mnr-voucher-fields', $parameters, version: 1);
$result = $this->platform->ask($conversation, $model, ['langfuse_prompt' => $this->prompts->reference('mnr-voucher-fields', 1)]);
```

`PromptRegistryInterface::reference()` resolves the actual version (also for a label or the latest), and the generation is linked to that prompt version in Langfuse.

#### Sessions and users

```php
$result = $this->platform->ask($conversation, $model, ['langfuse_session_id' => $runId, 'langfuse_user_id' => 'reporting-cron']);
```

Groups traces into a Langfuse session and attributes them to a user, so session cost and filters work. Pass ids, never names or e-mail addresses. `trace_name`, `trace_content`, `langfuse_prompt`, `langfuse_session_id` and `langfuse_user_id` are removed before the call reaches the provider.

#### What a trace measures

- The duration covers the whole traced call, including any wait for ai-bundle's rate limiter: it is the latency the caller saw, not only the provider's response time.
- A trace that exceeds Langfuse's request size limit (a very long conversation) is rejected with HTTP 413; the async handler sends it to the failure transport without retrying, and the synchronous flusher logs and drops it. Keep huge documents in attachments, which are recorded as `{mime, size}` only.

### Decision Tracing (TypeSafe Jev)

When ai-bundle registers TypeSafe Jev (`providers.typesafe`), `DecisionPlatformInterface::decide()` is traced the way Langfuse's own [TypeSafe integration](https://langfuse.com/integrations/model-providers/typesafe) records it: a generation named `typesafe-system-one`, the model TypeSafe answered with (e.g. `jev-1.13.0` for `jev-latest`), the request `{state, model, questions}` as input, the typed answers with their probabilities as output, and the input tokens as usage. [Jev as a judge](https://langfuse.com/docs/evaluation/evaluation-methods/jev-as-a-judge) evaluators run inside Langfuse on these traces and need nothing from this bundle.

### Usage Metrics and Cost Tracking

The bundle automatically extracts and sends usage metrics in the proper format for Langfuse:

- **Prompt tokens**: Input token count
- **Completion tokens**: Output token count
- **Total tokens**: Combined count
- **Cached and reasoning tokens**: when the provider reports them, as `prompt_tokens_details.cached_tokens` and `completion_tokens_details.reasoning_tokens`
- **Model information**: For accurate cost calculation
- **Proper generation structure**: Trace → Generation hierarchy

This enables accurate cost tracking and usage analysis in the Langfuse dashboard.

### Async Trace Flushing

For production applications, enable asynchronous trace processing to eliminate latency impact:

#### 1. Configure Messenger Transport

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            langfuse_traces:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%/langfuse-traces'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
        routing:
            'Lingoda\LangfuseBundle\Message\FlushLangfuseTrace': langfuse_traces
```

#### 2. Enable Async Processing

```yaml
# config/packages/lingoda_langfuse.yaml
lingoda_langfuse:
    tracing:
        enabled: true
        sampling_rate: 1.0
        async_flush:
            enabled: true
            message_bus: 'messenger.bus.default'  # optional, defaults to messenger.bus.default
```

#### 3. Run Message Consumers

```bash
php bin/console messenger:consume langfuse_traces --time-limit=3600

# or multiple workers for high throughput
php bin/console messenger:consume langfuse_traces --limit=100
php bin/console messenger:consume langfuse_traces --limit=100
...
```

**Benefits:**
- **Zero latency impact** on AI operations
- **Higher throughput** for AI-intensive applications
- **Reliable delivery** with retry mechanisms
- **Scalable processing** with multiple workers

### Prompt Management

The bundle provides a complete prompt management system with intelligent caching and fallback storage:

```php
use Lingoda\LangfuseBundle\Prompt\PromptRegistryInterface;
use Lingoda\AiSdk\Prompt\Conversation;

class EmailService
{
    public function __construct(
        private PromptRegistryInterface $prompts
    ) {}

    public function generateWelcomeEmail(string $userName): Conversation
    {
        // Intelligent cascade: Cache → API → Storage
        return $this->prompts->get('welcome_email');
    }

    public function generateCustomEmail(string $promptName, array $variables): Conversation
    {
        // Get specific version with variables
        $prompt = $this->prompts->getCompiled($promptName, $variables);

        return $prompt;
    }
}
```

**Prompt Flow:**
1. **Cache lookup** - Fast in-memory retrieval
2. **API call** - Fresh fetch from Langfuse if cache miss
3. **Storage fallback** - Local storage if API unavailable
4. **Automatic caching** - Cache API results for future use

#### Storage Configuration Options

**Path-based Storage (Default):**
```yaml
lingoda_langfuse:
    prompts:
        fallback:
            storage:
                path: '%kernel.project_dir%/var/prompts'
```

**Flysystem Integration (Cloud Storage):**
```yaml
# Configure Flysystem adapter first
flysystem:
    storages:
        prompts.storage:
            adapter: 's3'
            options:
                bucket: 'my-prompts-bucket'
                region: 'us-east-1'

# Reference in Langfuse config
lingoda_langfuse:
    prompts:
        fallback:
            storage:
                service: 'prompts.storage'
```

### Manual Tracing

For operations the decorators do not cover, trace them through `TraceManagerInterface`: the same path, sampling, `trace_content` handling and flushing as the automatic traces.

```php
use Lingoda\AiSdk\Result\TextResult;
use Lingoda\LangfuseBundle\Tracing\TraceManagerInterface;

class SummaryService
{
    public function __construct(
        private TraceManagerInterface $traceManager
    ) {}

    public function summarize(string $text): TextResult
    {
        return $this->traceManager->trace(
            'custom-summary',
            ['model' => 'my-local-model'], // a model makes it a generation
            $text,
            fn () => $this->runSummary($text) // returns a ResultInterface
        );
    }
}
```

### Langfuse v4 Ingestion

Traces are sent to Langfuse's OpenTelemetry endpoint (`POST /api/public/otel/v1/traces`, OTLP/HTTP JSON, header `x-langfuse-ingestion-version: 4`), the [v4 ingestion path](https://langfuse.com/integrations/native/opentelemetry/migration-to-v4). The legacy `/api/public/ingestion` endpoint is served only until November 16, 2026 and is not used. Each trace is one root observation, a generation when the result names a model, carrying input, output, usage (`input`, `output`, `total`, `input_cached_tokens`, `output_reasoning_tokens`), model, prompt link, level and metadata as `langfuse.*` attributes. Prompt management uses `GET /api/public/prompts`, which v4 keeps.

## Configuration Reference

```yaml
lingoda_langfuse:
    # Connection settings
    connection:
        public_key: string              # Required: Langfuse public key
        secret_key: string              # Required: Langfuse secret key
        host: string                    # Default: https://cloud.langfuse.com
        timeout: int                    # Default: 30 (seconds)
        retry:
            max_attempts: int           # Default: 3
            delay: int                  # Default: 1000 (milliseconds)

    # Tracing configuration
    tracing:
        enabled: bool                   # Default: true
        sampling_rate: float            # Default: 1.0 (0.0-1.0)
        export_timeout: int             # Default: 3 (seconds per trace; synchronous tracing blocks the call this long at most)
        async_flush:
            enabled: bool               # Default: false
            message_bus: string         # Default: 'messenger.default_bus'

    # Prompt management
    prompts:
        caching:
            enabled: bool               # Default: false
            ttl: int                    # Default: 3600 (seconds)
            service: string             # Default: 'cache.app'
        fallback:
            enabled: bool               # Default: false (auto-enabled with storage config)
            storage:
                path: string            # File system path
                # OR
                service: string         # Flysystem service ID
```

## Console Commands

### Test Connection

Verify your Langfuse API credentials and connection:

```bash
php bin/console langfuse:test-connection
```

### Cache Prompts

Cache specific prompts from Langfuse to local fallback storage:

```bash
# Cache specific prompts
php bin/console langfuse:cache-prompt --prompt=greeting --prompt=goodbye

# Preview without saving
php bin/console langfuse:cache-prompt --prompt=greeting --dry-run

# Force overwrite existing cached prompts
php bin/console langfuse:cache-prompt --prompt=greeting --force
```

**Options:**
- `--prompt|-p`: Specify prompt name(s) to cache (required, can be used multiple times)
- `--dry-run`: Preview what would be cached without saving files
- `--force`: Overwrite existing cached prompts

### Performance Optimization

**Sync Mode (Default):**
- Immediate trace delivery
- ~50-100ms latency per operation
- Guaranteed delivery
- Good for low-traffic applications

**Async Mode:**
- Zero latency impact on AI operations
- Requires message queue infrastructure
- Higher throughput potential
- Eventual consistency

**Sampling Configuration:**
```yaml
lingoda_langfuse:
    tracing:
        sampling_rate: 0.1  # Trace 10% of operations
```

## Development

### Running Tests

```bash
# Install dependencies
composer install

# Run PHPUnit tests
vendor/bin/phpunit

# Run static analysis
vendor/bin/phpstan analyse

# Check code style
vendor/bin/ecs check

# Fix code style
vendor/bin/ecs check --fix
```

## Architecture

The bundle follows clean architecture principles with focused, single-responsibility services:

### Core Components

- **LangfusePlatformDecorator**: Decorates AI SDK to enable automatic tracing
- **TraceManager**: Coordinates trace data preparation and timing
- **SyncTraceFlusher**: Handles synchronous flushing with generation support
- **AsyncTraceFlusher**: Dispatches traces to message queue
- **FlushLangfuseTraceHandler**: Processes async messages (delegates to sync flusher)
- **PromptRegistry**: Manages prompt lifecycle with caching and storage
- **PromptClient**: Handles API communication with Langfuse
- **OtlpTraceExporter**: Sends each trace to Langfuse's OpenTelemetry endpoint

### Trace Processing Architecture

```
AI Operation → Platform Decorator → TraceManager → FlushService → Langfuse
                                          ↓             ↓
                                    TraceFlusherInterface
                                          ↓
                                    SyncTraceFlusher
                                          ↓
                                    Creates Trace → Generation → Usage Details
                                          ↓
                                    Langfuse API
```

**Async Flow:**
```
AI Operation → TraceManager → AsyncTraceFlusher → Message Queue
                                                      ↓
                                           FlushLangfuseTraceHandler
                                                      ↓
                                                SyncTraceFlusher
                                                      ↓
                                                 Langfuse API
```

### Prompt Management Architecture

```
Request → PromptRegistry → Cache → Langfuse API → Storage
                             ↓         ↓           ↓
                       Fast Return  Fresh Data    Fallback
```

## Architecture Principles

### Design Philosophy
- **Direct Langfuse Integration**: Clean, direct API communication without intermediary layers
- **Simple Trace Structure**: AI operations create trace → generation structure
- **Async/Sync Flexibility**: Choose between immediate or queued processing

## Logging

The bundle uses a dedicated `langfuse` Monolog channel for all operations. Configure it in your Monolog configuration:

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - langfuse
    handlers:
        langfuse:
            type: stream
            path: '%kernel.logs_dir%/langfuse.log'
            level: debug
            channels: [langfuse]
```

## Troubleshooting

### Common Issues

1. **Connection failures**: Verify your API keys and host URL using `langfuse:test-connection`
2. **Missing prompts**: Use `langfuse:cache-prompt` to cache prompts locally for offline access
3. **Async processing not working**: Ensure Symfony Messenger is configured and consumers are running
4. **Token usage not tracked**: Verify the AI SDK returns proper usage metrics

### Debug Mode

Enable debug logging to troubleshoot issues:

```yaml
monolog:
    handlers:
        langfuse:
            level: debug  # Set to debug level
```

## License

MIT License. See [LICENSE](LICENSE) for details.
