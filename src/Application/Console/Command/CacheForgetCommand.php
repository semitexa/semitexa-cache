<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Console\Command;

use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:forget')]
final class CacheForgetCommand extends Command
{
    /**
     * The container's manager: one built here with `new` had no tenant store
     * and no bound resolver, so under tenant:run it acted on the default
     * tenant's keys and reported success.
     */
    #[InjectAsReadonly]
    protected CacheManagerInterface $cache;

    protected function configure(): void
    {
        $this
            ->setName('cache:forget')
            ->setDescription('Remove a single cache entry by key.')
            ->addArgument(
                name: 'key',
                mode: InputArgument::REQUIRED,
                description: 'The cache key to forget.',
            )
            ->addArgument(
                name: 'namespace',
                mode: InputArgument::OPTIONAL,
                description: 'The cache namespace the key belongs to (default: root namespace).',
                default: '',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');
        $namespace = (string) $input->getArgument('namespace');

        if ($key === '') {
            $output->writeln('<error>Cache key must not be empty.</error>');
            return Command::FAILURE;
        }

        $manager = $this->cache;
        $scoped = $namespace !== '' ? $manager->withNamespace($namespace) : $manager;
        $scoped->forget($key);

        $nsLabel = $namespace !== '' ? " in namespace '{$namespace}'" : '';
        $output->writeln("<info>Forgot cache key '{$key}'{$nsLabel}.</info>");

        return Command::SUCCESS;
    }
}
