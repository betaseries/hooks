<?php

namespace Hooks\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Command\Command;

/**
 * Class InstallCommand
 *
 * @package Hooks\Command
 */
class InstallCommand extends Command
{
    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('install');
        $this->setDescription('Install documented hooks.');
        $this->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Working directory.', '.');
        $this->addArgument('branch', InputArgument::OPTIONAL, 'Branch name.', 'master');
    }

    /**
     * @param InputInterface  $input  Input
     * @param OutputInterface $output Output
     *
     * @return null
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $dir = $input->getOption('dir');
        $branch = $input->getArgument('branch');

        if (!file_exists($dir . '/hooks.yml')) {
            throw new \Exception('No file found (Looking for ' . $dir . '/hooks.yml)');
        }

        $hooksData = file_get_contents($dir . '/hooks.yml');
        $yaml = Yaml::parse($hooksData);
        $cmds = [];

        if (is_array($yaml[$branch])) {
            $cmds = $yaml[$branch];
        } elseif (is_array($yaml['all'])) {
            $cmds = $yaml['all'];
        }

        putenv('CURRENT_BRANCH=' . $branch);
        putenv('CURRENT_BRANCH_SANITIZED=' . str_replace('/', '_', $branch));

        foreach ($cmds as $cmd) {
            $output->writeln('~> ' . $cmd);
            system($cmd);
        }

        return null;
    }
}