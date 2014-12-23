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
}