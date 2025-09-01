<?php

declare(strict_types = 1);

namespace Lingoda\LangfuseBundle\Command;

use Lingoda\LangfuseBundle\Exception\LangfuseException;
use Lingoda\LangfuseBundle\Prompt\PromptRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'langfuse:cache-prompt',
    description: 'Cache specific prompts from Langfuse to fallback storage'
)]
final class CachePromptCommand extends Command
{
    public function __construct(
        private readonly PromptRegistryInterface $promptRegistry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be cached without actually saving files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force overwrite existing cached prompts')
            ->addOption('prompt', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Specific prompt name(s) to cache (e.g., --prompt=ping --prompt=greeting)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $promptNames = $input->getOption('prompt');
        Assert::isArray($promptNames);

        $io->title('Langfuse Prompt Caching');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode - no files will be saved');
        }

        $io->section('Configuration');
        $io->text('Fallback storage: Storage configured for caching prompts locally');

        // Check if specific prompts were requested
        if (empty($promptNames)) {
            $io->warning('No prompt names specified.');
            $io->note('The Langfuse public API does not support listing all prompts.');
            $io->info('Usage: php bin/console langfuse:cache-prompt --prompt=ping --prompt=greeting');
            $io->note('Available options:');
            $io->listing([
                '--prompt=NAME: Specify prompt name(s) to cache',
                '--dry-run: Show what would be cached without saving',
                '--force: Overwrite existing cached prompts'
            ]);
            return Command::SUCCESS;
        }

        try {
            $io->section('Caching specific prompts from Langfuse...');
            $cached = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($promptNames as $name) {
                $io->text('Processing: ' . $name);

                try {
                    // Check if prompt exists in storage and if we should overwrite
                    $existsInStorage = $this->promptRegistry->has($name);
                    if ($existsInStorage && !$force) {
                        $io->text("  → Skipping (exists in storage, use --force to overwrite)");
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $io->text("  → Would fetch and cache: {$name}");
                        // Count as cached in dry-run mode
                    } else {
                        // This will fetch from API, cache it, and save to storage
                        $promptDetails = $this->promptRegistry->getRawPrompt($name, useCache: false); // Skip cache to ensure fresh data
                        $io->text("  → Cached: {$name}");
                    }
                    $cached++;
                } catch (LangfuseException $e) {
                    $io->text("  → Error caching {$name}: " . $e->getMessage());
                    $errors++;
                }
            }

            // Summary
            $io->section('Summary');

            if ($dryRun) {
                $io->info(sprintf('Would cache %d prompt(s)', $cached));
            } else {
                $io->success(sprintf('Cached: %d, Skipped: %d, Errors: %d', $cached, $skipped, $errors));
            }

            return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (LangfuseException $e) {
            $io->error('Failed to fetch prompts from Langfuse: ' . $e->getMessage());

            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error('Unexpected error: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
