<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Attribute;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Attribute\AsCommand(
    name: 'config:export',
    description: 'Exports the currently used configuration.'
)]
class ConfigExport extends Command implements LoggerAwareInterface
{

    use LoggerAwareTrait;

    protected InputInterface $input;

    protected OutputInterface $output;

    protected null|ContainerInterface $container;

    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    /**
     * @var array{
     *     exitCode: int,
     * }
     */
    protected array $result = [
        'exitCode' => 0,
    ];
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        try {
            $this
                ->validate()
                ->doIt();
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());

            return max($e->getCode(), 1);
        }

        return $this->result['exitCode'];
    }

    protected function validate(): static
    {
        return $this;
    }

    protected function doIt(): static
    {
        $config = $this->container->getParameter('config');
        $jsonFlags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE;
        $this->output->writeln(json_encode($config, $jsonFlags) ?: '{}');

        return $this;
    }
}
