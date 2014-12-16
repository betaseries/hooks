<?php

namespace Hooks\Tools;

use Symfony\Component\Yaml\Yaml;

/**
 * Class ConfigTools
 *
 * @package Hooks\Tools
 */
class ConfigTools
{
    /**
     * @param array $default Default config
     *
     * @return array
     */
    public static function getLocalConfig($default = [])
    {
        try {
            $config = Yaml::parse(file_get_contents($_SERVER['HOME'] . '/.hooks.yml'));
        } catch (\Exception $e) {
            $config = $default;
        }

        return $config;
    }

    /**
     * @param string $dir Repository directory
     *
     * @return array
     * @throws \Exception
     */
    public static function getRepositoryConfig($dir)
    {
        if (!file_exists($dir . '/hooks.yml')) {
            throw new \Exception('No file found (Looking for ' . $dir . '/hooks.yml)');
        }

        $hooksData = file_get_contents($dir . '/hooks.yml');
        $yaml = Yaml::parse($hooksData);

        return $yaml;
    }
}