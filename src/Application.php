<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli;

use Sweetchuck\ComposerLockDiff\LockDiffer;
use Sweetchuck\ComposerLockDiff\Reporter\ConsoleTableReporter;
use Sweetchuck\ComposerLockDiff\Reporter\JiraTableReporter;
use Sweetchuck\ComposerLockDiff\Reporter\JsonReporter;
use Sweetchuck\ComposerLockDiff\Reporter\MarkdownTableReporter;
use Sweetchuck\ComposerLockDiffCli\Command\ConfigExport;
use Sweetchuck\ComposerLockDiffCli\Command\Report;
use Sweetchuck\ComposerLockDiffCli\Config\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Console\Application as ApplicationBase;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition as ServiceDefinition;
use Symfony\Component\DependencyInjection\Reference as ServiceReference;

/**
 * @property null|\League\Container\DefinitionContainerInterface $container
 */
class Application extends ApplicationBase
{
    protected null|ContainerInterface $container = null;

    public function setContainer(ContainerInterface $container): static
    {
        $this->container = $container;

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @throws \Exception
     */
    public function initialize(): static
    {
        $this
            ->initializeContainer()
            ->initializeConfig()
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

        if ($this->container->has('composer-lock-differ') === false) {
            $this->container->register('composer-lock-differ', LockDiffer::class);
        }

        $reporter_pairs = [
            'console_table' => ConsoleTableReporter::class,
            'markdown_table' => MarkdownTableReporter::class,
            'jira_table' => JiraTableReporter::class,
            'json' => JsonReporter::class,
        ];
        foreach ($reporter_pairs as $name => $class) {
            $service = new ServiceDefinition($class);
            $service->setShared(false);
            $this->container->setDefinition("composer-lock-diff.reporter.$name", $service);
        }

        return $this;
    }

    /**
     * @throws \Exception
     */
    protected function initializeConfig(): static
    {
        $configDirs = [
            dirname(__DIR__) . '/config',
            getenv('HOME') . '/.config/' . $this->getName(),
        ];
        $fileLocator = new FileLocator($configDirs);
        $configFilePaths = (array) $fileLocator->locate('config.yaml', null, false);

        $loaderResolver = new LoaderResolver([
            new YamlFileLoader($fileLocator),
        ]);
        $delegatingLoader = new DelegatingLoader($loaderResolver);

        $config = [];
        foreach ($configFilePaths as $configFilePath) {
            $config = array_replace_recursive(
                $config,
                $delegatingLoader->load($configFilePath),
            );
        }

        // @todo Populate default values.
        $this->container->setParameter('config', $config);

        return $this;
    }

    protected function initializeCommands(): static
    {
        $logger = $this->container->get('logger');

        $cmdReport = new Report();
        $cmdReport->setContainer($this->container);
        $cmdReport->setLogger($logger);
        $this->add($cmdReport);

        $cmdConfigExport = new ConfigExport();
        $cmdConfigExport->setContainer($this->container);
        $cmdReport->setLogger($logger);
        $this->add($cmdConfigExport);

        return $this;
    }
}
