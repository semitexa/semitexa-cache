<?php
declare(strict_types=1);
namespace Semitexa\Cache\Console;

use Semitexa\Cache\Service\CacheManager;
use Semitexa\Core\Attributes\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:tags:flush')]
final class CacheTagsFlushCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('cache:tags:flush')
            ->setDescription('Flush all cache entries associated with the given tags.')
            ->addArgument(
                name: 'tags',
                mode: InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                description: 'One or more tag names to flush.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $tags */
        $tags = $input->getArgument('tags');

        if (empty($tags)) {
            $output->writeln('<error>At least one tag name is required.</error>');
            return Command::FAILURE;
        }

        $manager = new CacheManager();
        $count = $manager->flushTags(...$tags);

        $tagList = implode(', ', array_map(static fn(string $t) => "'{$t}'", $tags));
        $output->writeln("<info>Flushed {$count} cache entries tagged with {$tagList}.</info>");

        return Command::SUCCESS;
    }
}
