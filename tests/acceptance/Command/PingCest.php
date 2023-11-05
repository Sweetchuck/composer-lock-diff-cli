<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Tests\Acceptance\Command;

use Sweetchuck\ComposerLockDiffCli\Tests\AcceptanceTester;

class PingCest
{

    public function pingSuccess(AcceptanceTester $I): void
    {
        $pharPath = $I->grabPharPath();
        $I->assertNotEmpty($pharPath);

        $I->runShellCommand(
            sprintf(
                '%s ping',
                escapeshellcmd($pharPath),
            ),
        );
    }
}
