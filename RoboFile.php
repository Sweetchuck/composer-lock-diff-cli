<?php

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandResult;
use League\Container\Container as LeagueContainer;
use NuvoleWeb\Robo\Task\Config\loadTasks as ConfigLoader;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Collection\CollectionBuilder;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Contract\TaskInterface;
use Robo\State\Data as RoboStateData;
use Robo\Tasks;
use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\Robo\Composer\ComposerTaskLoader;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
use Sweetchuck\Robo\Phpstan\PhpstanTaskLoader;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class RoboFile extends Tasks implements LoggerAwareInterface, ConfigAwareInterface
{
    use LoggerAwareTrait;
    use ConfigLoader;
    use ConfigAwareTrait;
    use ComposerTaskLoader;
    use GitTaskLoader;
    use PhpcsTaskLoader;
    use PhpstanTaskLoader;

    protected ?RoboStateData $mainState = null;

    /**
     * @var array<string, mixed>
     */
    protected array $composerInfo = [];

    /**
     * @var array<string, mixed>
     */
    protected array $codeceptionInfo = [];

    /**
     * @var string[]
     */
    protected array $codeceptionSuiteNames = [];

    protected string $packageVendor = '';

    protected string $packageName = '';

    protected string $binDir = 'vendor/bin';

    protected string $gitHook = '';

    protected string $envVarNamePrefix = '';

    /**
     * Allowed values: local, dev, ci, prod.
     */
    protected string $environmentType = '';

    /**
     * Allowed values: local, jenkins, travis, circleci.
     */
    protected string $environmentName = '';

    protected Filesystem $fs;

    public function __construct()
    {
        $this->fs = new Filesystem();
        $this
            ->initComposerInfo()
            ->initEnvVarNamePrefix()
            ->initEnvironmentTypeAndName();
    }

    /**
     * Exports the currently used Robo configuration.
     *
     * @command config:export
     *
     * @option string $format
     *     Default: yaml
     */
    public function cmdConfigExportExecute(): CommandResult
    {
        return CommandResult::data($this->getConfig()->export());
    }

    # region Git hook callbacks
    /**
     * Git "pre-commit" hook callback.
     *
     * @command githook:pre-commit
     *
     * @hidden
     *
     * @initLintReporters
     */
    public function cmdGitHookPreCommitExecute(): CollectionBuilder
    {
        $this->gitHook = 'pre-commit';

        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskCodeceptRunSuites());
    }
    # endregion

    # region Lint
    /**
     * @hook pre-command @initLintReporters
     */
    public function onHookPreCommandInitLintReporters(): void
    {
        $lintServices = BaseReporter::getServices();
        $container = $this->getContainer();
        foreach ($lintServices as $name => $class) {
            if ($container->has($name)) {
                continue;
            }

            if ($container instanceof LeagueContainer) {
                $container
                    ->add($name, $class)
                    ->setShared(false);
            }
        }
    }

    /**
     * Runs all the configured static code analyzers.
     *
     * @command lint
     *
     * @initLintReporters
     */
    public function cmdLintExecute(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTask($this->taskComposerValidate())
            ->addTask($this->getTaskPhpcsLint())
            ->addTask($this->getTaskPhpstanAnalyze());
    }

    /**
     * Runs `phpcs`.
     *
     * @command lint:phpcs
     *
     * @initLintReporters
     */
    public function cmdLintPhpcsExecute(): TaskInterface
    {
        return $this->getTaskPhpcsLint();
    }

    /**
     * Runs `phpstan analyze`.
     *
     * @command lint:phpstan
     *
     * @initLintReporters
     */
    public function cmdLintPhpstanExecute(): TaskInterface
    {
        return $this->getTaskPhpstanAnalyze();
    }

    protected function getTaskPhpcsLint(): TaskInterface
    {
        $options = [
            'failOn' => 'warning',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
        ];

        if ($this->environmentType === 'ci' && $this->environmentName === 'jenkins') {
            $options['failOn'] = 'never';
            $options['lintReporters']['lintCheckstyleReporter'] = $this
                ->getContainer()
                ->get('lintCheckstyleReporter')
                ->setDestination('tests/_log/machine/checkstyle/phpcs.psr2.xml');
        }

        if ($this->gitHook === 'pre-commit') {
            return $this
                ->collectionBuilder()
                ->addTask($this
                    ->taskPhpcsParseXml()
                    ->setAssetNamePrefix('phpcsXml.'))
                ->addTask($this
                    ->taskGitListStagedFiles()
                    ->setPaths(['*.php' => true])
                    ->setDiffFilter(['d' => false])
                    ->setAssetNamePrefix('staged.'))
                ->addTask($this
                    ->taskGitReadStagedFiles()
                    ->setCommandOnly(true)
                    ->setWorkingDirectory('.')
                    ->deferTaskConfiguration('setPaths', 'staged.fileNames'))
                ->addTask($this
                    ->taskPhpcsLintInput($options)
                    ->deferTaskConfiguration('setFiles', 'files')
                    ->deferTaskConfiguration('setIgnore', 'phpcsXml.exclude-patterns'));
        }

        return $this->taskPhpcsLintFiles($options);
    }

    protected function getTaskPhpstanAnalyze(): TaskInterface
    {
        /** @var \Sweetchuck\LintReport\Reporter\VerboseReporter $verboseReporter */
        $verboseReporter = $this->getContainer()->get('lintVerboseReporter');
        $verboseReporter->setFilePathStyle('relative');

        return $this
            ->taskPhpstanAnalyze()
            ->setNoProgress(true)
            ->setErrorFormat('json')
            ->addLintReporter('lintVerboseReporter', $verboseReporter);
    }
    # endregion

    # region Test
    /**
     * @hook validate test
     */
    public function cmdTestValidate(CommandData $commandData): void
    {
        $args = $commandData->arguments();
        $this->validateArgCodeceptionSuiteNames($args['suiteNames']);
    }

    /**
     * Runs all the tests.
     *
     * @param array<string> $suiteNames
     *   One or more Codeception suite names.
     *   If omitted then all suites will be tested.
     *
     * @command test
     */
    public function cmdTestExecute(array $suiteNames): CollectionBuilder
    {
        return $this->getTaskCodeceptRunSuites($suiteNames);
    }

    /**
     * @param string[] $suiteNames
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    protected function getTaskCodeceptRunSuites(array $suiteNames = []): CollectionBuilder
    {
        if (!$suiteNames) {
            $suiteNames = ['all'];
        }

        $phpExecutables = array_filter(
            (array) $this->getConfig()->get('php.executables'),
            fn(array $php): bool => !empty($php['enabled']),
        );

        $cb = $this->collectionBuilder();
        foreach ($suiteNames as $suiteName) {
            foreach ($phpExecutables as $phpExecutable) {
                $cb->addTask($this->getTaskCodeceptRunSuite($suiteName, $phpExecutable));
            }
        }

        return $cb;
    }

    /**
     * @param string $suite
     * @param array<string, mixed> $php
     */
    protected function getTaskCodeceptRunSuite(string $suite, array $php): CollectionBuilder
    {
        $this->initCodeceptionInfo();

        $withCoverageHtml = $this->environmentType === 'dev';
        $withCoverageXml = $this->environmentType === 'ci';

        $withUnitReportHtml = $this->environmentType === 'dev';
        $withUnitReportXml = $this->environmentType === 'ci';

        $logDir = $this->getReportsDir();

        $cmdPattern = '';
        $cmdArgs = [];
        foreach ($php['envVars'] ?? [] as $envName => $envValue) {
            $cmdPattern .= "{$envName}";
            if ($envValue === null) {
                $cmdPattern .= ' ';
            } else {
                $cmdPattern .= '=%s ';
                $cmdArgs[] = escapeshellarg($envValue);
            }
        }

        $cmdPattern .= '%s';
        $cmdArgs[] = $php['command'];

        $cmdPattern .= ' %s';
        $cmdArgs[] = escapeshellcmd("{$this->binDir}/codecept");

        $cmdPattern .= ' --ansi';
        $cmdPattern .= ' --verbose';
        $cmdPattern .= ' --debug';

        $cb = $this->collectionBuilder();
        if ($withCoverageHtml) {
            $cmdPattern .= ' --coverage-html=%s';
            $cmdArgs[] = escapeshellarg("human/coverage/$suite/html");

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/human/coverage/$suite")
            );
        }

        if ($withCoverageXml) {
            $cmdPattern .= ' --coverage-xml=%s';
            $cmdArgs[] = escapeshellarg("machine/coverage/$suite/coverage.xml");
        }

        if ($withCoverageHtml || $withCoverageXml) {
            $cmdPattern .= ' --coverage=%s';
            $cmdArgs[] = escapeshellarg("machine/coverage/$suite/coverage.serialized");

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/machine/coverage/$suite")
            );
        }

        if ($withUnitReportHtml) {
            $cmdPattern .= ' --html=%s';
            $cmdArgs[] = escapeshellarg("human/junit/junit.$suite.html");

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/human/junit")
            );
        }

        if ($withUnitReportXml) {
            $cmdPattern .= ' --xml=%s';
            $cmdArgs[] = escapeshellarg("machine/junit/junit.$suite.xml");

            $cb->addTask(
                $this
                    ->taskFilesystemStack()
                    ->mkdir("$logDir/machine/junit")
            );
        }

        $cmdPattern .= ' run';
        if ($suite !== 'all') {
            $cmdPattern .= ' %s';
            $cmdArgs[] = escapeshellarg($suite);
        }

        $envDir = $this->codeceptionInfo['paths']['envs'];
        $envFileName = "{$this->environmentType}.{$this->environmentName}";
        if (file_exists("$envDir/$envFileName.yml")) {
            $cmdPattern .= ' --env %s';
            $cmdArgs[] = escapeshellarg($envFileName);
        }

        if ($this->environmentType === 'ci' && $this->environmentName === 'jenkins') {
            // Jenkins has to use a post-build action to mark the build "unstable".
            $cmdPattern .= ' || [[ "${?}" == "1" ]]';
        }

        $command = vsprintf($cmdPattern, $cmdArgs);

        return $cb
            ->addCode(function () use ($command, $php) {
                $this->output()->writeln(strtr(
                    '<question>[{name}]</question> runs <info>{command}</info>',
                    [
                        '{name}' => 'Codeception',
                        '{command}' => $command,
                    ]
                ));

                $process = Process::fromShellCommandline(
                    $command,
                    null,
                    $php['envVars'] ?? null,
                    null,
                    null,
                );

                return $process->run(function ($type, $data) {
                    switch ($type) {
                        case Process::OUT:
                            $this->output()->write($data);
                            break;

                        case Process::ERR:
                            $this->errorOutput()->write($data);
                            break;
                    }
                });
            });
    }

    /**
     * @return array<string>
     */
    protected function getCodeceptionSuiteNames(): array
    {
        if (!$this->codeceptionSuiteNames) {
            $this->initCodeceptionInfo();

            $suiteFiles = Finder::create()
                ->in($this->codeceptionInfo['paths']['tests'])
                ->files()
                ->name('*.suite.dist.yml')
                ->name('*.suite.yml')
                ->depth(0);

            foreach ($suiteFiles as $suiteFile) {
                $parts = explode('.', $suiteFile->getBasename());
                $this->codeceptionSuiteNames[] = reset($parts);
            }

            $this->codeceptionSuiteNames = array_unique($this->codeceptionSuiteNames);
        }

        return $this->codeceptionSuiteNames;
    }

    /**
     * @param string[] $suiteNames
     */
    protected function validateArgCodeceptionSuiteNames(array $suiteNames): static
    {
        if (!$suiteNames) {
            return $this;
        }

        $invalidSuiteNames = array_diff($suiteNames, $this->getCodeceptionSuiteNames());
        if ($invalidSuiteNames) {
            throw new InvalidArgumentException(
                'The following Codeception suite names are invalid: ' . implode(', ', $invalidSuiteNames),
                1,
            );
        }

        return $this;
    }
    # endregion

    # region Phar
    /**
     * Generates an executable PHAR file.
     *
     * @param array<mixed> $options
     *
     * @command phar:build
     */
    public function cmdPharBuildExecute(
        ?string $destination = null,
        array $options = [
            'tag' => '',
        ]
    ): TaskInterface {
        if ($destination === null) {
            $appName = $this->getAppName();
            $destination = "./artifacts/$appName.phar";
        }

        if (!$this->fs->isAbsolutePath($destination)) {
            $destination = getcwd() . "/$destination";
        }

        return $this
            ->collectionBuilder()
            ->addCode($this->getTaskPharBuildInit())
            ->addTask($this->getTaskPharBuildPrepareWorkingDirectory())
            ->addTask($this->getTaskPharBuildCopyProjectCollect())
            ->addTask($this->getTaskPharBuildPharCopyProjectDoIt())
            ->addTask($this->taskComposerInstall()->option('no-dev'))
            ->addTask($this->getTaskPharBuildComposerPackagePaths())
            ->addCode($this->getTaskPharBuildPhar($destination, $options['tag']));
    }

    /**
     * To check that what is inside a *.phar file.
     *
     * @command phar:content
     *
     * @option string $format
     *     Default: list
     */
    public function cmdPharContentExecute(?string $path = null): CommandResult
    {
        if ($path === null) {
            $appName = $this->getAppName();
            $path = "./artifacts/$appName.phar";
        }

        $path = realpath($path);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("phar://$path"));
        $data = [];
        foreach ($files as $file) {
            $data[] = str_replace("phar://$path", '.', (string) $file);
        }

        return CommandResult::data($data);
    }

    protected function getTaskPharBuildInit(): \Closure
    {
        return function (RoboStateData $data): int {
            $this->mainState = $data;
            $data['srcDir'] = getcwd();

            return 0;
        };
    }

    protected function getTaskPharBuildPrepareWorkingDirectory(): TaskInterface
    {
        return $this
            ->taskTmpDir(basename(__DIR__), (string) realpath('..'))
            ->cwd();
    }

    protected function getTaskPharBuildCopyProjectCollect(): TaskInterface
    {
        return $this
            ->taskGitListFiles()
            ->setAssetNamePrefix('project.')
            ->deferTaskConfiguration('setWorkingDirectory', 'srcDir');
    }

    protected function getTaskPharBuildPharCopyProjectDoIt(): TaskInterface
    {
        return $this
            ->taskForEach()
            ->iterationMessage('Something happening with {key}', ['foo' => 'bar'])
            ->deferTaskConfiguration('setIterable', 'project.files')
            ->withBuilder(function (CollectionBuilder $builder, string $fileName) {
                $builder->addTask($this->taskFilesystemStack()->copy(
                    $this->mainState['srcDir'] . '/' . $fileName,
                    "./$fileName",
                ));
            });
    }

    protected function getTaskPharBuildComposerPackagePaths(): TaskInterface
    {
        $task = $this->taskComposerPackagePaths();
        $task->deferTaskConfiguration('setWorkingDirectory', 'path');

        return $task;
    }

    protected function getTaskPharBuildPhar(string $pharPathname, string $version): \Closure
    {
        return function () use ($pharPathname, $version): int {
            $this->logger->info(
                "Create PHAR; version: {version} ; path: {pharPathname}",
                [
                    'pharPathname' => $pharPathname,
                    'version' => $version,
                ],
            );
            $vendorDir = 'vendor';

            $filesExtra = [
                $this->mainState['path'] . '/composer.json',
            ];
            $files = new \AppendIterator();
            $files->append(
                (new Finder())
                    ->in('./src/')
                    ->files()
                    ->name('*.php')
                    ->getIterator(),
            );
            $files->append(
                (new Finder())
                    ->in("./$vendorDir/")
                    ->files()
                    ->notPath("psr/log/Psr/Log/Test")
                    ->notPath("bin")
                    ->notPath("tests")
                    ->notName('composer.json')
                    ->notName('composer.lock')
                    ->notName('codeception*.*')
                    ->notName('phpcs.xml')
                    ->notName('phpcs.xml.dist')
                    ->notName('phpunit.xml')
                    ->notName('phpunit.xml.dist')
                    ->notName('robo.yml')
                    ->notName('robo.yml.dist')
                    ->notName('RoboFile.php')
                    ->notName('*.md')
                    ->ignoreVCS(true)
                    ->getIterator(),
            );

            $packageDirs = (new Finder())
                ->in($vendorDir)
                ->directories()
                ->depth(1);

            /** @var \Symfony\Component\Finder\SplFileInfo $packageDir */
            foreach ($packageDirs as $packageDir) {
                if (!$packageDir->isLink()) {
                    continue;
                }

                $packageFiles = (new Finder())
                    ->in((string) realpath($packageDir->getPathname()))
                    ->files()
                    ->notPath('bin')
                    ->notPath('reports')
                    ->notPath('tests')
                    ->notPath('Test')
                    ->notPath('vendor')
                    ->notName('codeception.*')
                    ->notName('composer.json')
                    ->notName('composer.lock')
                    ->notName('phpcs.xml')
                    ->notName('phpcs.xml.dist')
                    ->notName('phpunit.xml')
                    ->notName('phpunit.xml.dist')
                    ->notName('phpstan.neon')
                    ->notName('phpstan.dist.neon')
                    ->notName('robo.yml')
                    ->notName('robo.yml.dist')
                    ->notName('RoboFile.php')
                    ->notName('*.md')
                    ->ignoreVCS(true);
                foreach ($packageFiles as $packageFile) {
                    $filesExtra[] = $this->mainState['path']
                        . '/' . $packageDir->getPathname()
                        . '/' . $packageFile->getRelativePathname();
                }
            }

            $files->append(new \ArrayIterator($filesExtra));

            $appName = $this->getAppName();
            if (file_exists($pharPathname)) {
                unlink($pharPathname);
            }

            $startFile = "bin/$appName";
            $startContent = file($startFile) ?: [];
            array_shift($startContent);
            if ($version !== '') {
                $startContent = preg_replace(
                    '/^\$version = \'.*?\';$/m',
                    sprintf('$version = %s;', var_export($version, true)),
                    $startContent,
                );
            }

            $this->fs->mkdir(dirname($pharPathname), 0777 - umask());
            $phar = new \Phar($pharPathname, 0);
            $phar->buildFromIterator($files, $this->mainState['path']);
            $phar->addFromString($startFile, implode('', $startContent));
            $phar->setStub($this->getPharStubCode($appName, $startFile));
            chmod($pharPathname, 0777 - umask());

            return 0;
        };
    }

    protected function getPharStubCode(string $appName, string $startFile): string
    {
        return sprintf(
            <<<'PHP'
#!/usr/bin/env php
<?php
Phar::mapPhar(%s);
set_include_path(%s . get_include_path());
require(%s);
__HALT_COMPILER();
PHP,
            var_export($appName, true),
            var_export("phar://$appName/", true),
            var_export($startFile, true),
        );
    }
    # endregion

    protected function errorOutput(): ?OutputInterface
    {
        $output = $this->output();

        return ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;
    }

    protected function initEnvVarNamePrefix(): static
    {
        $this->envVarNamePrefix = strtoupper(str_replace('-', '_', $this->packageName));

        return $this;
    }

    protected function initEnvironmentTypeAndName(): static
    {
        $this->environmentType = (string) getenv($this->getEnvVarName('environment_type'));
        $this->environmentName = (string) getenv($this->getEnvVarName('environment_name'));

        if (!$this->environmentType) {
            if (getenv('CI') === 'true') {
                // Travis, GitLab and CircleCI.
                $this->environmentType = 'ci';
            } elseif (getenv('JENKINS_HOME')) {
                $this->environmentType = 'ci';
                if (!$this->environmentName) {
                    $this->environmentName = 'jenkins';
                }
            }
        }

        if (!$this->environmentName && $this->environmentType === 'ci') {
            if (getenv('GITLAB_CI') === 'true') {
                $this->environmentName = 'gitlab';
            } elseif (getenv('TRAVIS') === 'true') {
                $this->environmentName = 'travis';
            } elseif (getenv('CIRCLECI') === 'true') {
                $this->environmentName = 'circleci';
            }
        }

        if (!$this->environmentType) {
            $this->environmentType = 'dev';
        }

        if (!$this->environmentName) {
            $this->environmentName = 'local';
        }

        return $this;
    }

    protected function getEnvVarName(string $name): string
    {
        return "{$this->envVarNamePrefix}_" . strtoupper($name);
    }

    protected function initComposerInfo(): static
    {
        $composerFileName = getenv('COMPOSER') ?: 'composer.json';
        if ($this->composerInfo || !is_readable($composerFileName)) {
            return $this;
        }

        $this->composerInfo = json_decode(file_get_contents($composerFileName) ?: '{}', true);
        [$this->packageVendor, $this->packageName] = explode('/', $this->composerInfo['name']);

        if (!empty($this->composerInfo['config']['bin-dir'])) {
            $this->binDir = $this->composerInfo['config']['bin-dir'];
        }

        return $this;
    }

    protected function initCodeceptionInfo(): static
    {
        if ($this->codeceptionInfo) {
            return $this;
        }

        $default = [
            'paths' => [
                'tests' => 'tests',
                'log' => 'tests/_log',
            ],
        ];
        $dist = [];
        $local = [];

        if (is_readable('codeception.dist.yml')) {
            $dist = Yaml::parse(file_get_contents('codeception.dist.yml') ?: '{}');
        }

        if (is_readable('codeception.yml')) {
            $local = Yaml::parse(file_get_contents('codeception.yml') ?: '{}');
        }

        $this->codeceptionInfo = array_replace_recursive($default, $dist, $local);

        return $this;
    }

    protected function getAppName(): string
    {
        $parts = explode('/', $this->composerInfo['bin'][0]);

        return (string) end($parts);
    }

    protected function getReportsDir(): string
    {
        $this->initCodeceptionInfo();

        return !empty($this->codeceptionInfo['paths']['output']) ?
            $this->codeceptionInfo['paths']['output']
            : 'tests/_output';
    }
}
