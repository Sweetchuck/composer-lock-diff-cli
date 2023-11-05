<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Tests\Unit\Command;

use Codeception\Test\Unit;
use Sweetchuck\ComposerLockDiffCli\Application;
use Sweetchuck\ComposerLockDiffCli\Tests\UnitTester;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Sweetchuck\ComposerLockDiffCli\Command\Report
 */
class ReportTest extends Unit
{
    protected UnitTester $tester;

    public function testGenerateSuccess(): void
    {
        $casesDir = codecept_data_dir('fixtures/cases');

        $application = new Application();
        $application->initialize();
        $command = $application->find('report');
        $command->setApplication($application);
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [
                'leftLock' => "$casesDir/basic/left.lock.json",
                'rightLock' => "$casesDir/basic/right.lock.json",
                'leftJson' => "$casesDir/basic/left.comp.json",
                'rightJson' => "$casesDir/basic/right.comp.json",
            ],
            [
                'capture_stderr_separately' => true,
                'decorated' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            ],
        );

        $expectedStdOutput = <<< 'TEXT'
            +------+--------+-------+-------------+-----------------+
            | Name | Before | After | Required    | Direct          |
            +------+--------+-------+-------------+-----------------+
            | a/b  | 1.2.3  | 1.3.0 | prod : prod | direct : direct |
            | a/d  | 3.4.5  | 3.5.0 | prod : prod | direct : direct |
            +------+--------+-------+-------------+-----------------+

            TEXT;
        /** @var \Symfony\Component\Console\Output\StreamOutput $output */
        $output = $commandTester->getOutput();
        rewind($output->getStream());
        $stdOutput = stream_get_contents($output->getStream());
        $stdError = $commandTester->getErrorOutput();

        $this->tester->assertSame('', $stdError, 'stdError is OKAY');

        $this->tester->assertSame(
            $expectedStdOutput,
            $stdOutput,
            'stdOutput is OKAY',
        );

        $expectedExitCode = 0;
        $this->tester->assertSame(
            $expectedExitCode,
            $commandTester->getStatusCode(),
            'exit code',
        );
    }
}
