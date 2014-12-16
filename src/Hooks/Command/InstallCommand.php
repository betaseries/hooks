<?php

namespace Hooks\Command;

use GuzzleHttp\Client;
use Hooks\Tools\ConfigTools;
use Hooks\Tools\ServiceTools;
use Hooks\Tools\SystemTools;
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
        $this->addOption('pull-branch', null, InputOption::VALUE_REQUIRED, 'Pull Request branch name.', null);
        $this->addOption('pull-sha', null, InputOption::VALUE_REQUIRED, 'Pull Request SHA for status.', null);
        $this->addOption('pull-repository', null, InputOption::VALUE_REQUIRED, 'Pull Request Repository for status.', null);
        $this->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'Git clone URL.', null);
        $this->addOption('silent', null, InputOption::VALUE_NONE, 'No notification.');
        $this->addArgument('branch', InputArgument::OPTIONAL, 'Branch name.', null);
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
        $url = $input->getOption('url');
        $silent = $input->getOption('silent');
        $pullBranch = $input->getOption('pull-branch');
        $pullSHA = $input->getOption('pull-sha');
        $pullRepository = $input->getOption('pull-repository');
        $pullDir = str_replace('/', '-', $pullBranch);
        $branch = $input->getArgument('branch');

        if (!$branch) {
            $branch = trim(substr(file_get_contents($dir . '/.git/HEAD'), 16));
            if (empty($branch)) {
                $branch = 'master';
            }
        }

        if ($pullRepository && $pullSHA) {
            ServiceTools::sendGitHubStatus($pullRepository, $pullSHA, 'pending');
        }

        $newDir = date('YmdHis');
        $baseDir = $dir;

        chdir($baseDir);

        if ($url) {
            $outputResult .= $systemTools->executeCommand('git clone ' . $url . ' ' . $newDir) . PHP_EOL . PHP_EOL;
            $dir .= '/' . $newDir;
            chdir($dir);
            if ($pullBranch) {
                $outputResult .= $systemTools->executeCommand('git checkout ' . $pullBranch) . PHP_EOL . PHP_EOL;
            } else {
                $outputResult .= $systemTools->executeCommand('git checkout ' . $branch) . PHP_EOL . PHP_EOL;
            }
        }

        $yaml = ConfigTools::getRepositoryConfig($dir);
        $cmds = [];

        if (isset($yaml[$branch]) && is_array($yaml[$branch])) {
            $cmds = $yaml[$branch];
        } elseif (isset($yaml['all']) && is_array($yaml['all'])) {
            $cmds = $yaml['all'];
        }

        $outputResult .= $systemTools->putEnvVar('TERM=VT100');
        $outputResult .= $systemTools->putEnvVar('CURRENT_BRANCH=' . $branch);
        $outputResult .= $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . str_replace('/', '_', $branch));

        if ($url && isset($cmds['release']) && is_array($cmds['release'])) {
            if ($pullBranch) {
                if (!is_dir($baseDir . '/pulls')) {
                    mkdir($baseDir . '/pulls');
                }
                if (!is_dir($baseDir . '/pulls/' . $pullDir)) {
                    mkdir($baseDir . '/pulls/' . $pullDir);
                }

                $repoBaseDir = $baseDir . '/pulls/' . $pullDir;
            } else {
                if (!is_dir($baseDir . '/' . $cmds['release']['directory'])) {
                    mkdir($baseDir . '/' . $cmds['release']['directory']);
                }

                $repoBaseDir = $baseDir . '/' . $cmds['release']['directory'];
            }

            if (!is_dir($repoBaseDir . '/shared')) {
                mkdir($repoBaseDir . '/shared');
            }
            if (!is_dir($repoBaseDir . '/releases')) {
                mkdir($repoBaseDir . '/releases');
            }

            rename($baseDir . '/' . $newDir, $repoBaseDir . '/releases/' . $newDir);
            chdir($repoBaseDir . '/releases/' . $newDir);
        } elseif ($url) {
            throw new \Exception('You cannot set a Git clone URL without any release info.');
        }

        if (isset($cmds['env']) && is_array($cmds['env'])) {
            foreach ($cmds['env'] as $env) {
                $outputResult .= $systemTools->putEnvVar($env, true) . PHP_EOL . PHP_EOL;
            }
        }

        if (isset($cmds['commands']) && is_array($cmds['commands'])) {
            foreach ($cmds['commands'] as $cmd) {
                $outputResult .= $systemTools->executeCommand($cmd, $output, true) . PHP_EOL . PHP_EOL;
            }
        }

        if ($pullBranch && isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['open']) && is_array($yaml['pulls']['open'])) {
            foreach ($yaml['pulls']['open'] as $cmd) {
                $outputResult .= $systemTools->executeCommand($cmd, $output, true) . PHP_EOL . PHP_EOL;
            }
        }

        if (is_array($cmds['release'])) {
            if ($url && isset($cmds['release']['shared']) && is_array($cmds['release']['shared'])) {
                foreach ($cmds['release']['shared'] as $item) {
                    $output->writeln('Linking shared item ' . $item);
                    $outputResult .= $systemTools->executeCommand('rm -Rf ' . $repoBaseDir . '/releases/' . $newDir . $item . ' && ln -fs ' . $repoBaseDir . '/shared' . $item . ' ' . $repoBaseDir . '/releases/' . $newDir . $item) . PHP_EOL . PHP_EOL;
                }
            }
            if (isset($cmds['release']['after']) && is_array($cmds['release']['after'])) {
                foreach ($cmds['release']['after'] as $cmd) {
                    $outputResult .= $systemTools->executeCommand($cmd) . PHP_EOL . PHP_EOL;
                }
            }
            if ($url) {
                if (isset($cmds['release']['keep']) && is_numeric($cmds['release']['keep']) && $cmds['release']['keep'] > 0) {
                    $dirs = glob($repoBaseDir . '/releases/*', GLOB_ONLYDIR);
                    rsort($dirs);
                    $i = 0;
                    foreach ($dirs as $dir) {
                        $i++;
                        if ($i > $cmds['release']['keep']) {
                            $output->writeln('Removing extra release ' . basename($dir));
                            $outputResult .= $systemTools->executeCommand('rm -Rf ' . $dir) . PHP_EOL . PHP_EOL;
                        }
                    }
                }
                $output->writeln('Linking release ' . $newDir);
                $outputResult .= $systemTools->executeCommand('rm -f ' . $repoBaseDir . '/current && ln -fs ' . $repoBaseDir . '/releases/' . $newDir . ' ' . $repoBaseDir . '/current') . PHP_EOL . PHP_EOL;
            } elseif (is_array($cmds['release']['standalone'])) {
                foreach ($cmds['release']['standalone'] as $cmd) {
                    $outputResult .= $systemTools->executeCommand($cmd) . PHP_EOL . PHP_EOL;
                }
            }
        }

        $config = ConfigTools::getLocalConfig([
            'after' => []
        ]);

        if (is_array($config) && isset($config['after']) && is_array($config['after'])) {
            foreach ($config['after'] as $cmd) {
                $outputResult .= $systemTools->executeCommand($cmd, $output, true) . PHP_EOL . PHP_EOL;
            }
        }

        if (!$silent && is_array($cmds['release'])) {
            $config = ConfigTools::getLocalConfig([
                'email' => [
                    'sender' => null,
                    'address' => null,
                ]
            ]);

            exec('git log -1 --pretty=%B', $sysOutput);
            $lastCommit = trim(implode("\n", $sysOutput));

            if ($pullBranch && isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['url'])) {
                $liveUrl = sprintf($yaml['pulls']['url'], $pullDir);
            } else {
                $liveUrl = $cmds['release']['url'];
            }

            if ($pullRepository && $pullSHA) {
                ServiceTools::sendGitHubStatus($pullRepository, $pullSHA, 'success', $liveUrl, 'Staging environment has been updated.');
            }

            if (is_array($yaml['emails'])) {
                $transport = \Swift_MailTransport::newInstance();
                $mailer = \Swift_Mailer::newInstance($transport);

                $message = \Swift_Message::newInstance('WebHook ' . $cmds['release']['name'])
                    ->setFrom(array($config['email']['address'] => $config['email']['sender']))
                    ->setTo($yaml['emails'])
                    ->setBody($outputResult);
                $result = $mailer->send($message);
            }

            if (is_array($yaml['slack']) && !empty($yaml['slack']['url']) && !empty($yaml['slack']['channel'])) {
                $config = ConfigTools::getLocalConfig([
                    'messages' => ['New release']
                ]);

                if (!isset($config['messages']) || !is_array($config['messages'])) {
                    $config = [
                        'messages' => ['New release']
                    ];
                }

                $randMessage = $config['messages'][array_rand($config['messages'])];

                $name = $cmds['release']['name'];
                $launched = $randMessage . ': <' . $liveUrl . '|' . $name . '>';

                if (empty($liveUrl)) {
                    $launched = $randMessage . ': ' . $name;
                } elseif (empty($name) && !empty($liveUrl)) {
                    $launched = $randMessage . ': <' . $liveUrl . '>';
                }

                if (!empty($liveUrl) || !empty($name)) {
                    $client = new Client();
                    $response = $client->post($yaml['slack']['url'], [
                            'body' => [
                                'payload' => json_encode([
                                        'channel' => $yaml['slack']['channel'],
                                        'pretext' => $launched,
                                        'fallback' => $launched,
                                        'color' => '#B8CB82',
                                        'fields' => [
                                            [
                                                'title' => 'Release',
                                                'value' => 'Last commit: ' . $lastCommit,
                                                'short' => false,
                                            ],
                                        ]
                                    ]
                                )
                            ]
                        ]
                    );
                }
            }
        }

        return null;
    }
}