<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Command;

use Lingoda\LangfuseBundle\Client\TraceClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'langfuse:test-connection',
    description: 'Test connection to Langfuse API'
)]
final class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly TraceClient $client
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Langfuse Connection');

        try {
            $io->text('Attempting to connect to Langfuse API...');

            if ($this->client->testConnection()) {
                $io->success('Successfully connected to Langfuse API!');

                return Command::SUCCESS;
            }
            $io->error('Failed to connect to Langfuse API');

            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error(sprintf('Connection failed: %s', $e->getMessage()));

            if ($output->isVerbose()) {
                $io->text(sprintf('Exception: %s', $e::class));
                $io->text(sprintf('File: %s:%d', $e->getFile(), $e->getLine()));
            }

            return Command::FAILURE;
        }
    }
}
