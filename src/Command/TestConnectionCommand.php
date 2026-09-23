<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Command;

use Lingoda\LangfuseBundle\Client\LangfuseConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'langfuse:test-connection',
    description: 'Test connection to Langfuse API'
)]
final class TestConnectionCommand extends Command
{
    private const string PROJECTS_ENDPOINT = 'api/public/projects';

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly LangfuseConnection $connection,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct();
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Langfuse Connection');

        try {
            $io->text(sprintf('Attempting to connect to %s ...', $this->connection->host));

            // Authenticated read: proves the host and both keys without writing a test trace
            $status = $this->httpClient->request('GET', $this->connection->url(self::PROJECTS_ENDPOINT), [
                'headers' => ['Authorization' => $this->connection->authorizationHeader()],
                'timeout' => $this->connection->timeout,
            ])->getStatusCode();

            if ($status === 200) {
                $io->success('Successfully connected to Langfuse API!');

                return Command::SUCCESS;
            }
            $io->error(sprintf('Failed to connect to Langfuse API (HTTP %d)', $status));

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(sprintf('Connection failed: %s', $e->getMessage()));

            if ($output->isVerbose()) {
                $io->text(sprintf('Exception: %s', $e::class));
                $io->text(sprintf('File: %s:%d', $e->getFile(), $e->getLine()));
            }

            return Command::FAILURE;
        }
    }
}
