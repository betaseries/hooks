<?php

namespace Hooks\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Knp\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Class TriggerCommand
 *
 * @package Hooks\Command
 */
class TriggerCommand extends Command
{
    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('trigger');
        $this->setDescription('Trigger documented hooks.');
        $this->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Working directory.', '.');
        $this->addArgument('name', InputArgument::REQUIRED, 'Trigger name.');
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
        $trigger = $input->getArgument('name');

        if (!file_exists($dir . '/hooks.yml')) {
            throw new \Exception('No file found (Looking for ' . $dir . '/hooks.yml)');
        }

        $hooksData = file_get_contents($dir . '/hooks.yml');
        $yaml = Yaml::parse($hooksData);
        $cmds = [];

        if (isset($yaml['triggers'][$trigger]) && is_array($yaml['triggers'][$trigger])) {
            foreach ($yaml['triggers'][$trigger] as $cmd) {
                $output->writeln('~>' . $cmd);
                system($cmd);
            }
        }

        $branch = trim(substr(file_get_contents($dir . '/.git/HEAD'), 16));
        putenv('CURRENT_BRANCH=' . $branch);
        putenv('CURRENT_BRANCH_SANITIZED=' . str_replace('/', '_', $branch));

        foreach ($cmds as $cmd) {
            $output->writeln('~> ' . $cmd);
            system($cmd);
        }

        return null;
    }
}