<?php

namespace Hooks\Command;

use Hooks\Tools\ConfigTools;
use Hooks\Tools\SystemTools;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Class CleanCommand
 *
 * @package Hooks\Command
 */
class CleanCommand extends Command
{
    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('clean');
        $this->setDescription('Clean Pull Request directory.');
        $this->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Pull Request main directory.');
        $this->addOption('pull-branch', null, InputOption::VALUE_OPTIONAL, 'Pull Request branch name.', null);
        $this->addOption('silent', null, InputOption::VALUE_NONE, 'No notification.');
        $this->addArgument('branch', InputArgument::REQUIRED, 'Parent branch name.');
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
        $silent = $input->getOption('silent');
        $branch = $input->getArgument('branch');
        $pullBranch = $input->getOption('pull-branch');

        if (!$silent) {
            $outputFile = $dir . '/../../logs/clean-' . $systemTools->sanitizeBranchName($pullBranch) . '.log';
            $systemTools->setOutputFile($outputFile);
        }

        $systemTools->putEnvVar('CURRENT_BRANCH=' . $pullBranch);
        $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . $systemTools->sanitizeBranchName($pullBranch));

        $yaml = ConfigTools::getRepositoryConfig($dir . '/current');
        $cmds = [];

        if (isset($yaml[$branch]) && is_array($yaml[$branch])) {
            $cmds = $yaml[$branch];
        } elseif (isset($yaml['all']) && is_array($yaml['all'])) {
            $cmds = $yaml['all'];
        }


        $systemTools->changeDirectory($dir . '/current');
        if (isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['close']) && is_array($yaml['pulls']['close'])) {
            foreach ($yaml['pulls']['close'] as $cmd) {
                $systemTools->executeCommand($cmd, $output, true);
            }
        }

        $systemTools->changeDirectory($dir . '/..');

        // Wait for all commands to be executed before removing the directory (lsof purpose)
        sleep(5);

        $systemTools->executeCommand('rm -Rf ' . $dir);
        $systemTools->cleanRecordedSHAs($dir . '/../..');

        if (!$silent && isset($yaml['emails']) && is_array($yaml['emails']) && null !== $outputFile) {
            $config = ConfigTools::getLocalConfig([
                'email' => [
                    'sender' => null,
                    'address' => null,
                ]
            ]);

            $outputResult = $systemTools->cleanAnsiColors(file_get_contents($outputFile));

            if (isset($yaml['emails']) && is_array($yaml['emails'])) {
                $transport = \Swift_MailTransport::newInstance();
                $mailer = \Swift_Mailer::newInstance($transport);

                $message = \Swift_Message::newInstance('WebHook ' . $cmds['release']['name'])
                    ->setFrom(array($config['email']['address'] => $config['email']['sender']))
                    ->setTo($yaml['emails'])
                    ->setBody($outputResult);
                $result = $mailer->send($message);
            }
        }

        return null;
    }
}