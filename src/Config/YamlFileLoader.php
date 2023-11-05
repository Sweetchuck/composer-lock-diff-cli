<?php

declare(strict_types = 1);

namespace Sweetchuck\ComposerLockDiffCli\Config;

use Symfony\Component\Config\Loader\FileLoader;
use Symfony\Component\Yaml\Yaml;

class YamlFileLoader extends FileLoader
{

    /**
     * {@inheritdoc}
     */
    public function supports(mixed $resource, string $type = null): bool
    {
        return is_string($resource)
            && pathinfo($resource, \PATHINFO_EXTENSION) === 'yaml';
    }

    /**
     * {@inheritdoc}
     *
     * @phpstan-return array<string, mixed>
     */
    public function load(mixed $resource, string $type = null): array
    {
        return Yaml::parse(file_get_contents($resource) ?: '{}');
    }
}
