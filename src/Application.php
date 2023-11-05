<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli;

use Sweetchuck\ComposerLockDiffCli\Command\Ping;
use Symfony\Component\Console\Application as ApplicationBase;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition as ServiceDefinition;
use Symfony\Component\DependencyInjection\Reference as ServiceReference;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @property \Symfony\Component\DependencyInjection\ContainerBuilder $container
 */
class Application extends ApplicationBase implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function initialize(): static
    {
        $this
            ->initializeContainer()
            ->initializeCommands();

        return $this;
    }

    protected function initializeContainer(): static
    {
        if ($this->container === null) {
            $this->container = new ContainerBuilder();
        }

        if ($this->container->has('output') === false) {
            $service = new ServiceDefinition(ConsoleOutput::class);
            $this->container->setDefinition('output', $service);
        }

        if ($this->container->has('logger') === false) {
            $service = new ServiceDefinition(ConsoleLogger::class);
            $service->addArgument(new ServiceReference('output'));
            $this->container->setDefinition('logger', $service);
        }

        if ($this->container->has('filesystem') === false) {
            $this->container->register('filesystem', Filesystem::class);
        }

        return $this;
    }

    protected function initializeCommands(): static
    {
        $cmdPing = new Ping();
        $cmdPing->setContainer($this->container);
        $cmdPing->setLogger($this->container->get('logger'));
        $this->add($cmdPing);

        return $this;
    }
}
