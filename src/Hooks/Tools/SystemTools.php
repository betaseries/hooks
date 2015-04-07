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
    private $_outputFile = null;

    /**
     * @param OutputInterface $output
     */
    public function __construct(OutputInterface $output)
    {
        $this->_output = $output;
    }

    /**
     * @param string $outputFile
     */
    public function setOutputFile($outputFile)
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        if (null !== $this->_outputFile && null !== $outputFile) {
            rename($this->_outputFile, $outputFile);
        }

        $this->_outputFile = $outputFile;
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
            $this->_output->writeln($result);
        }

        if (null !== $this->_outputFile) {
            file_put_contents($this->_outputFile, $result . PHP_EOL . PHP_EOL, FILE_APPEND);
            exec($cmd . ' 2>&1 >> ' . $this->_outputFile, $output, $returnStatus);
            file_put_contents($this->_outputFile, PHP_EOL, FILE_APPEND);
        } else {
            system($cmd . ' 2>&1', $returnStatus);
        }

        if (0 !== $returnStatus) {
            $this->_output->writeln(PHP_EOL . 'Returned code: ' . $returnStatus);
        }
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
            $this->_output->writeln($result);
        }

        $calculatedEnv = preg_replace_callback(
            '/\$\{([^\}]+)\}/',
            function ($matches) {
                return getenv($matches[1]);
            },
            $env
        );

        if (null !== $this->_outputFile) {
            file_put_contents($this->_outputFile, $result . PHP_EOL . PHP_EOL . '~> ' . $calculatedEnv . PHP_EOL . PHP_EOL, FILE_APPEND);
        }

        putenv($calculatedEnv);
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

        if (null !== $this->_outputFile) {
            file_put_contents($this->_outputFile, $result . PHP_EOL . PHP_EOL . '~> cd ' . $dir . PHP_EOL . PHP_EOL, FILE_APPEND);
        }

        chdir($dir);
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
     */
    public function deleteRecordedSHA($dir, $SHA)
    {
        @unlink($dir . '/.sha-' . $SHA);
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

        $json = json_decode(file_get_contents($dir . '/.sha-' . $SHA), true);

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
            $dir = $infos['dir'] . '/pulls/' . str_replace('/', '-', $infos['pull-branch']);

            if (!is_dir($dir)) {
                unlink($file);
            }
        }
    }

    /**
     * @param string $content
     *
     * @return mixed
     */
    public function cleanAnsiColors($content)
    {
        $content = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', "", $content);
        $content = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', "", $content);
        $content = preg_replace('/[\x03|\x1a]/', "", $content);

        return $content;
    }
}