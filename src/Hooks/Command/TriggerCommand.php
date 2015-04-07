<?php

namespace Hooks\Command;

use Hooks\Tools\SystemTools;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;
use Hooks\Tools\ConfigTools;

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
        $systemTools = new SystemTools($output);

        $dir = $input->getOption('dir');
        $trigger = $input->getArgument('name');

        $yaml = ConfigTools::getRepositoryConfig($dir);

        $branch = trim(substr(file_get_contents($dir . '/.git/HEAD'), 16));
        $systemTools->putEnvVar('CURRENT_BRANCH=' . $branch);
        $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . $systemTools->sanitizeBranchName($branch));

        if (isset($yaml['triggers'][$trigger]) && is_array($yaml['triggers'][$trigger])) {
            foreach ($yaml['triggers'][$trigger] as $cmd) {
                $systemTools->executeCommand($cmd);
            }
        }

        return null;
    }
}