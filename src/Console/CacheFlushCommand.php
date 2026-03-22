<?php
declare(strict_types=1);
namespace Semitexa\Cache\Console;

use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Service\CacheManager;
use Semitexa\Core\Attributes\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:flush')]
final class CacheFlushCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('cache:flush')
            ->setDescription('Flush all cache entries in a namespace.')
            ->addOption(
                name: 'namespace',
                shortcut: null,
                mode: InputOption::VALUE_OPTIONAL,
                description: 'The namespace to flush (default: root namespace).',
                default: '',
            )
            ->addOption(
                name: 'scope',
                shortcut: null,
                mode: InputOption::VALUE_OPTIONAL,
                description: 'Scope to flush: "tenant" or "global" (default: tenant).',
                default: 'tenant',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespace = (string) $input->getOption('namespace');
        $scopeValue = (string) $input->getOption('scope');

        $scope = match ($scopeValue) {
            'global' => CacheScope::Global,
            'tenant' => CacheScope::Tenant,
            default => null,
        };

        if ($scope === null) {
            $output->writeln("<error>Invalid scope '{$scopeValue}'. Use 'tenant' or 'global'.</error>");
            return Command::FAILURE;
        }

        $manager = new CacheManager();
        $count = $manager->scope($scope)->flushNamespace($namespace !== '' ? $namespace : null);

        $scopeLabel = $scope->value;
        $nsLabel = $namespace !== '' ? "'{$namespace}'" : '(root)';
        $output->writeln("<info>Flushed {$count} cache entries from {$scopeLabel} namespace {$nsLabel}.</info>");

        return Command::SUCCESS;
    }
}
