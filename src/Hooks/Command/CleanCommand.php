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
        $this->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Pull Request main directory.', '.');
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

        $outputResult = null;

        $dir = $input->getOption('dir');
        $silent = $input->getOption('silent');
        $branch = $input->getArgument('branch');

        $outputResult .= $systemTools->putEnvVar('CURRENT_BRANCH=' . $branch);
        $outputResult .= $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . str_replace('/', '_', $branch));

        $yaml = ConfigTools::getRepositoryConfig($dir . '/current');
        $cmds = [];

        if (isset($yaml[$branch]) && is_array($yaml[$branch])) {
            $cmds = $yaml[$branch];
        } elseif (isset($yaml['all']) && is_array($yaml['all'])) {
            $cmds = $yaml['all'];
        }

        chdir($dir);

        if (isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['close']) && is_array($yaml['pulls']['close'])) {
            foreach ($yaml['pulls']['close'] as $cmd) {
                $outputResult .= $systemTools->executeCommand($cmd, $output, true) . PHP_EOL . PHP_EOL;
            }
        }

        chdir($dir . '/..');

        $systemTools->executeCommand('rm -Rf ' . $dir);

        if (!$silent && isset($yaml['emails']) && is_array($yaml['emails'])) {
            $config = ConfigTools::getLocalConfig([
                'email' => [
                    'sender' => null,
                    'address' => null,
                ]
            ]);

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