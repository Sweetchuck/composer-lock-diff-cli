<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Sweetchuck\ComposerLockDiff\LockDiffEntry;
use Sweetchuck\ComposerLockDiff\ReporterInterface;
use Sweetchuck\Utils\Filter\CustomFilter;
use Sweetchuck\Utils\Filter\FilterInterface;
use Symfony\Component\Console\Attribute;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Attribute\AsCommand(
    name: 'report',
    description: 'Compares <null|left> and <null|right> composer.lock files.'
)]
class Report extends Command implements LoggerAwareInterface
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
     * @phpstan-var array<string, mixed>
     */
    protected array $diffArgs = [];

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
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setHelp(<<< 'TEXT'
                Both leftLock and rightLock arguments cannot be null.
                TEXT
            )
            ->addUsage(sprintf(
                '%s %s %s %s',
                "<(git show 'abc1234^:composer.lock' 2>/dev/null)",
                "<(git show 'abc1234:composer.lock')",
                "<(git show 'abc1234^:composer.json' 2>/dev/null)",
                "<(git show 'abc1234:composer.json')",
            ))
            ->setDefinition([
                new InputOption(
                    'format',
                    ['f'],
                    InputOption::VALUE_REQUIRED,
                    'Report format',
                    'table-plain',
                    $this->availableFormats(...),
                ),
                new InputArgument(
                    'leftLock',
                    InputArgument::REQUIRED,
                    'composer.lock on the left side. Hyphen (-) can be used for null.',
                ),
                new InputArgument(
                    'rightLock',
                    InputArgument::REQUIRED,
                    'composer.lock on the right side. Hyphen (-) can be used for null.',
                ),
                new InputArgument(
                    'leftJson',
                    InputArgument::OPTIONAL,
                    'composer.json on the left side. Hyphen (-) can be used for null.',
                ),
                new InputArgument(
                    'rightJson',
                    InputArgument::OPTIONAL,
                    'composer.json on the right side. Hyphen (-) can be used for null.',
                ),
            ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->diffArgs = [
            'leftLock' => null,
            'rightLock' => null,
            'leftJson' => null,
            'rightJson' => null,
        ];
        $this->result = [
            'exitCode' => 0,
        ];

        $this
            ->validate()
            ->doIt();

        return $this->result['exitCode'];
    }

    protected function validate(): static
    {
        $argNames = [
            'leftLock',
            'rightLock',
            'leftJson',
            'rightJson',
        ];
        foreach ($argNames as $argName) {
            $filePath = $this->processInputFilePath($this->input->getArgument($argName));
            if ($filePath === '-' || $filePath === null) {
                continue;
            }

            $json = null;
            if (str_starts_with($filePath, '{') === false) {
                $json = @file_get_contents($filePath);
                if ($json === '') {
                    // If the $filePath is created like this: "<(git show "${commit_hash}^:composer.lock" 2>/dev/null)"
                    // and the parent commit is not exists, because the given $commit_hash
                    // was the first commit in the repository,
                    // then it is equivalent to the "null" input.
                    continue;
                }

                if ($json === false) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'file path "%s" given for argument "%s" is invalid',
                            $filePath,
                            $argName,
                        ),
                    );
                }
            }

            $this->diffArgs[$argName] = json_decode($json ?: $filePath, true);

            if ($this->diffArgs[$argName] === null) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'JSON content for argument "%s" is invalid: %s',
                        $argName,
                        json_last_error_msg(),
                    ),
                );
            }

            if (!is_array($this->diffArgs[$argName])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'JSON content for argument "%s" is invalid: %s',
                        $argName,
                        'not an array',
                    ),
                );
            }
        }

        return $this;
    }

    protected function doIt(): static
    {
        $this->result = [
            'exitCode' => 0,
        ];

        /** @var \Sweetchuck\ComposerLockDiff\LockDiffer $lockDiffer */
        $lockDiffer = $this->container->get('composer-lock-differ');
        $diffEntries = $lockDiffer->diff(...$this->diffArgs);
        $reporter = $this->getReporter();
        $reporter->generate($diffEntries);

        return $this;
    }

    protected function getReporter(): ReporterInterface
    {
        $format = $this->input->getOption('format');
        $config = $this->container->getParameter('config');
        $serviceNameShort = $config['format'][$format]['service'] ?? $format;
        $serviceName = "composer-lock-diff.reporter.$serviceNameShort";
        /** @var \Sweetchuck\ComposerLockDiff\ReporterInterface $reporter */
        $reporter = $this->container->get($serviceName);
        $options = $config['format'][$format]['options'] ?? [];

        // @todo Use interface instead of \method_exists().
        if ($this->output instanceof StreamOutput
            && method_exists($reporter, 'setStream')
        ) {
            $reporter->setStream($this->output->getStream());
        }

        // @todo Same key can have different meaning for different reporters.
        foreach ($options['groups'] ?? [] as $groupId => $group) {
            if (!empty($group['filter'])) {
                $options['groups'][$groupId]['filter'] = $this->createFilterInstance($group['filter']);
            }
        }

        if (empty($options['groups'])) {
            unset($options['groups']);
        }

        if (empty($options['columns'])) {
            unset($options['columns']);
        }

        $reporter->setOptions($options);

        return $reporter;
    }

    protected function createFilterInstance(array $definition): ?FilterInterface
    {
        if ($definition['service'] === 'custom') {
            $filter = new CustomFilter();
            if (!empty($definition['options']['operator'])) {
                $operator = match ($definition['options']['operator']) {
                    'right-direct-prod' => function (LockDiffEntry $entry): bool {
                        return $entry->isRightDirectDependency && $entry->rightRequiredAs === 'prod';
                    },
                    'right-direct-dev' => function (LockDiffEntry $entry): bool {
                        return $entry->isRightDirectDependency && $entry->rightRequiredAs === 'dev';
                    },
                    default => null,
                };


                $filter->setOperator($operator);
            }

            return $filter;
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function availableFormats(): array
    {
        /** @phpstan-var array{format: array<string, mixed>} $config */
        $config = (array) $this->container->getParameter('config');

        return array_keys($config['format']);
    }

    protected function processInputFilePath(?string $filePath): ?string
    {
        if ($filePath === null) {
            return null;
        }

        return preg_replace(
            '@^/proc/self/fd/(?P<id>\d+)$@',
            'php://fd/$1',
            $filePath,
        );
    }
}
