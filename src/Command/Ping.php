<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Attribute;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

#[Attribute\AsCommand(
    name: 'ping',
    description: 'Dummy command.',
)]
class Ping extends Command implements ContainerAwareInterface, LoggerAwareInterface
{

    use ContainerAwareTrait;
    use LoggerAwareTrait;

    protected InputInterface $input;

    protected OutputInterface $output;

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
        } catch (\Exception $e) {
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
        $this->result = [
            'exitCode' => 0,
        ];

        $this->output->writeln('pong');

        return $this;
    }
}
