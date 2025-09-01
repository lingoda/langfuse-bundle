<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Client;

use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for prompt retrieval from Langfuse API.
 */
final class PromptClient
{
    private const string PROMPTS_ENDPOINT = 'api/public/prompts';

    public function __construct(
        private readonly TraceClient $client,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
    }

    /**
     * Get a prompt from Langfuse API via HTTP.
     *
     * @param string $name Prompt name
     * @param int|null $version Prompt version (null for latest)
     * @param string|null $label Prompt label
     *
     * @throws LangfuseException
     *
     * @return array<string, mixed> Prompt data
     */
    public function getPromptFromAPI(string $name, ?int $version = null, ?string $label = null): array
    {
        $queryParams = ['name' => $name];
        if ($version !== null) {
            $queryParams['version'] = (string) $version;
        }
        if ($label !== null) {
            $queryParams['label'] = $label;
        }

        try {
            return $this->makeGetRequest($queryParams);
        } catch (LangfuseException $e) {
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not found')) {
                throw new LangfuseException(sprintf('Prompt "%s" not found in Langfuse', $name), 404, $e);
            }
            throw $e;
        }
    }

    /**
     * Make a GET request to Langfuse API.
     *
     * @param array<string, mixed> $queryParams Query parameters
     *
     * @throws LangfuseException
     *
     * @return array<string, mixed> Response data
     */
    private function makeGetRequest(array $queryParams = []): array
    {
        if ($this->httpClient === null) {
            throw new LangfuseException('HTTP client not configured for prompt management');
        }

        $config = $this->client->getClient()->getConfig();
        $url = mb_rtrim($config->host, '/') . '/' . mb_ltrim(self::PROMPTS_ENDPOINT, '/');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $queryParams,
                'headers' => [
                    'Authorization' => $config->getAuthHeader(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            $body = $response->getContent();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new LangfuseException('Invalid JSON response from Langfuse API');
            }

            /** @var array<string, mixed> $data */
            return $data;
        } catch (TransportExceptionInterface $e) {
            throw new LangfuseException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '404')) {
                throw new LangfuseException('Resource not found', 404, $e);
            }
            throw new LangfuseException('Request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
