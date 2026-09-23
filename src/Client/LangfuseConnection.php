<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Client;

/**
 * Where and how to reach the Langfuse project: host, API keys and request timeout.
 */
final readonly class LangfuseConnection
{
    public function __construct(
        public string $host,
        public string $publicKey,
        #[\SensitiveParameter]
        private string $secretKey,
        public int $timeout = 30,
    ) {
    }

    public function url(string $path): string
    {
        return mb_rtrim($this->host, '/') . '/' . mb_ltrim($path, '/');
    }

    public function authorizationHeader(): string
    {
        return 'Basic ' . base64_encode($this->publicKey . ':' . $this->secretKey);
    }

    /**
     * @return array{host: string, publicKey: string, timeout: int}
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'publicKey' => $this->publicKey, 'timeout' => $this->timeout];
    }
}
