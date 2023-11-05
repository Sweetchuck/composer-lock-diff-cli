<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Tests\Acceptance\Command;

use Sweetchuck\ComposerLockDiffCli\Tests\AcceptanceTester;

class ReportCest
{

    public function reportSuccess(AcceptanceTester $I): void
    {
        $casesDir = codecept_data_dir('fixtures/cases');

        $pharPath = $I->grabPharPath();
        $I->assertNotEmpty($pharPath);

        $I->runShellCommand(
            sprintf(
                '%s report %s %s %s %s',
                escapeshellcmd($pharPath),
                "$casesDir/basic/left.lock.json",
                "$casesDir/basic/right.lock.json",
                "$casesDir/basic/left.comp.json",
                "$casesDir/basic/right.comp.json",
            ),
        );

        $expectedStdOutput = <<< 'TEXT'
            +------+--------+-------+-------------+-----------------+
            | Name | Before | After | Required    | Direct          |
            +------+--------+-------+-------------+-----------------+
            | a/b  | 1.2.3  | 1.3.0 | prod : prod | direct : direct |
            | a/d  | 3.4.5  | 3.5.0 | prod : prod | direct : direct |
            +------+--------+-------+-------------+-----------------+
            TEXT;

        $I->assertSame(
            $expectedStdOutput,
            $I->grabShellOutput(),
            'stdOutput',
        );
    }
}
