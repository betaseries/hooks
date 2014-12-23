<?php

namespace Hooks\Tools;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SystemTools
 *
 * @package Hooks\Tools
 */
class SystemTools
{
    /** @var OutputInterface */
    private $_output;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->_output = $output;
    }

    /**
     * @param string $cmd
     * @param bool   $displayCommand
     *
     * @return string|OutputInterface
     */
    public function executeCommand($cmd, $displayCommand=true)
    {
        $result = '~> ' . $cmd;

        if ($displayCommand) {
            $this->_output->writeln('~> ' . $cmd);
        }

        exec($cmd . ' 2>&1', $outputAndErrors);

        $trimmed = trim(implode(PHP_EOL, $outputAndErrors));

        if (!empty($trimmed)) {
            $result .= PHP_EOL . $trimmed;
        }

        return $result;
    }

    /**
     * @param string $env
     * @param bool   $displayCommand
     *
     * @return string|OutputInterface
     */
    public function putEnvVar($env, $displayCommand=true)
    {
        $result = '~> Setting environment variable ' . $env;

        if ($displayCommand) {
            $this->_output->writeln('~> Setting environment variable ' . $env);
        }

        putenv($env);

        return $result;
    }

    /**
     * @param string $dir
     * @param bool   $displayCommand
     *
     * @return string|OutputInterface
     */
    public function changeDirectory($dir, $displayCommand=true)
    {
        $result = '~> Changing current directory to ' . $dir;

        if ($displayCommand) {
            $this->_output->writeln('~> cd ' . $dir);
        }

        chdir($dir);

        return $result;
    }

    /**
     * @param string $dir
     * @param string $SHA
     * @param array  $infos
     */
    public function recordSHA($dir, $SHA, array $infos)
    {
        file_put_contents($dir . '/.sha-' . $SHA, json_encode($infos));
    }

    /**
     * @param string $dir
     * @param string $SHA
     *
     * @return bool|mixed
     */
    public function getRecordedSHA($dir, $SHA)
    {
        if (!file_exists($dir . '/.sha-' . $SHA)) {
            return false;
        }

        $json = json_decode(file_get_contents($dir . '/.' . $SHA), true);

        return $json;
    }

    /**
     * @param string $dir
     */
    public function cleanRecordedSHAs($dir)
    {
        $files = glob($dir . '/.sha-*');

        foreach ($files as $file) {
            $infos = json_decode(file_get_contents($file), true);

            if (!is_dir($infos['dir'])) {
                unlink($file);
            }
        }
    }
}