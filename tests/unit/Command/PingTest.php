<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Tests\Unit\Command;

use Codeception\Test\Unit;
use Sweetchuck\ComposerLockDiffCli\Application;
use Sweetchuck\ComposerLockDiffCli\Tests\UnitTester;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Sweetchuck\ComposerLockDiffCli\Command\Ping
 */
class PingTest extends Unit
{
    protected UnitTester $tester;

    public function testGenerateSuccess(): void
    {
        $application = new Application();
        $application->initialize();
        $command = $application->find('ping');
        $command->setApplication($application);
        $commandTester = new CommandTester($command);
        $commandTester->execute(
            [],
            [
                'decorated' => false,
                'verbosity' => OutputInterface::VERBOSITY_VERY_VERBOSE,
            ],
        );

        // @todo Somehow get the output, and check its content.
        $expectedExitCode = 0;
        $this->tester->assertSame(
            $expectedExitCode,
            $commandTester->getStatusCode(),
            "exit code $expectedExitCode",
        );
    }
}
