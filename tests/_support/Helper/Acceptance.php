<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Tests\Helper;

use Codeception\Module;

class Acceptance extends Module
{

    /**
     * @var array<string>
     */
    protected array $requiredFields = [];

    /**
     * @var array{
     *     pharPath: string,
     * }
     */
    protected array $config = [
        'pharPath' => './artifacts/composer-lock-diff.phar',
    ];

    public function grabPharPath(): string
    {
        return $this->config['pharPath'];
    }
}
