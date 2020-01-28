<?php

namespace Hooks\Command;

use Hooks\Tools\ConfigTools;
use Hooks\Tools\ServiceTools;
use Hooks\Tools\SystemTools;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Command\Command;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;

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
        $this->addOption('pull-force', null, InputOption::VALUE_NONE, 'Don\'t wait for all statuses to succeed.');
        $this->addOption('pull-id', null, InputOption::VALUE_REQUIRED, 'Pull Request ID.', null);
        $this->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'Git clone URL.', null);
        $this->addOption('update', null, InputOption::VALUE_NONE, 'Run a git pull on directory.');
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

        $dir = $input->getOption('dir');
        $url = $input->getOption('url');
        $silent = $input->getOption('silent');
        $pullBranch = $input->getOption('pull-branch');
        $pullSHA = $input->getOption('pull-sha');
        $pullRepository = $input->getOption('pull-repository');
        $pullForce = $input->getOption('pull-force');
        $pullId = $input->getOption('pull-id');
        $update = $input->getOption('update');
        $branch = $input->getArgument('branch');

        ServiceTools::$repository = $pullRepository;
        ServiceTools::$SHA = $pullSHA;

        if ($pullSHA && !$pullBranch) {
            // Check if we recorded this SHA in the directory
            if ($infos = $systemTools->getRecordedSHA($dir, $pullSHA)) {
                $url = $infos['url'];
                $pullBranch = $infos['pull-branch'];
                ServiceTools::$repository = $pullRepository = $infos['pull-repository'];
                $pullId = $infos['pull-id'];
                $branch = $infos['branch'];
            } else {
                throw new \Exception('No SHA record found in the directory.');
            }
        }

        $pullDir = $systemTools->sanitizeBranchName($pullBranch);

        if (!$branch) {
            $branch = trim(substr(file_get_contents($dir . '/.git/HEAD'), 16));
            if (empty($branch)) {
                $branch = 'master';
            }
        }

        $newDir = date('YmdHis');
        $baseDir = $dir;
        $outputFile = null;

        if (!$silent) {
            $outputFile = $dir . '/logs/install-' . $newDir . '.log';
            $systemTools->setOutputFile($outputFile);
        }

        $systemTools->changeDirectory($baseDir);

        if ($url) {
            $systemTools->executeCommand('git clone ' . $url . ' ' . $newDir);
            $dir .= '/' . $newDir;
            $systemTools->changeDirectory($dir);
            if ($pullBranch) {
                $systemTools->executeCommand('git checkout ' . $pullBranch);
            } else {
                $systemTools->executeCommand('git checkout ' . $branch);
            }
        } elseif ($update) {
            $systemTools->executeCommand('git pull origin');
        }

        $yaml = ConfigTools::getRepositoryConfig($dir);
        $cmds = [];

        if ($pullRepository && $pullSHA && !$pullForce) {
            $statuses = [];
            if (isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['statuses']) && is_array($yaml['pulls']['statuses'])) {
                foreach ($yaml['pulls']['statuses'] as $status) {
                    $statuses[] = $status;
                }
                $output->writeln('Required green statuses: ' . implode(', ', $statuses) . '.');
            }

            if (!ServiceTools::hasOnlyGreenGitHubStatuses($statuses)) {
                $output->writeln('All required statuses are not green, waiting.');
                ServiceTools::sendGitHubStatus('pending', null, 'Waiting for all statuses to succeed.');

                $infos = [
                    'dir' => $baseDir,
                    'url' => $url,
                    'pull-branch' => $pullBranch,
                    'pull-repository' => $pullRepository,
                    'pull-id' => $pullId,
                    'branch' => $branch,
                ];
                $systemTools->recordSHA($baseDir, $pullSHA, $infos);

                // If we cloned the git repo, remove it, it's useless.
                if ($baseDir != $dir) {
                    $systemTools->executeCommand('rm -Rf ' . $dir);
                }

                return null;
            } else {
                $output->writeln('All required statuses are green.');
                $systemTools->deleteRecordedSHA($baseDir, $pullSHA);
            }
        }

        ServiceTools::sendGitHubStatus('pending', null, 'Shipping…');

        if (isset($yaml[$branch]) && is_array($yaml[$branch])) {
            $cmds = $yaml[$branch];
        } elseif (isset($yaml['all']) && is_array($yaml['all'])) {
            $cmds = $yaml['all'];
        }

        // Overridings commands with pull commands
        if ($pullBranch && isset($yaml['pulls']['commands']) && is_array($yaml['pulls']['commands'])) {
            $cmds['commands'] = $yaml['pulls']['commands'];
        }

        // Overridings release with pull release
        if ($pullBranch && isset($yaml['pulls']['release']) && is_array($yaml['pulls']['release'])) {
            $cmds['release'] = array_merge($cmds['release'], $yaml['pulls']['release']);
        }

        $systemTools->putEnvVar('TERM=VT100');

        if ($pullBranch) {
            $systemTools->putEnvVar('CURRENT_BRANCH=' . $pullBranch);
            $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . $systemTools->sanitizeBranchName($pullBranch));
            $systemTools->putEnvVar('CURRENT_PULL_ID=' . $pullId);
        } else {
            $systemTools->putEnvVar('CURRENT_BRANCH=' . $branch);
            $systemTools->putEnvVar('CURRENT_BRANCH_SANITIZED=' . $systemTools->sanitizeBranchName($branch));
        }

        $repoBaseDir = null;

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
            $systemTools->changeDirectory($repoBaseDir . '/releases/' . $newDir);
        } elseif ($url) {
            throw new \Exception('You cannot set a Git clone URL without any release info.');
        }

        $systemTools->checkLockfile($repoBaseDir);

        $systemTools->putEnvVar('RELEASE_DIR=' . $repoBaseDir . '/current');

        if (isset($cmds['env']) && is_array($cmds['env'])) {
            foreach ($cmds['env'] as $env) {
                $systemTools->putEnvVar($env, true);
            }
        }

        if ($pullBranch && isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['env']) && is_array($yaml['pulls']['env'])) {
            foreach ($yaml['pulls']['env'] as $env) {
                $systemTools->putEnvVar($env, true);
            }
        }

        if ($pullBranch && isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['open']) && is_array($yaml['pulls']['open'])) {
            foreach ($yaml['pulls']['open'] as $cmd) {
                $systemTools->executeCommand($cmd, true);
            }
        }

        if ($url && isset($cmds['release']['shared']) && is_array($cmds['release']['shared'])) {
            foreach ($cmds['release']['shared'] as $item) {
                $output->writeln('Linking shared item ' . $item);
                $systemTools->executeCommand('rm -Rf ' . $repoBaseDir . '/releases/' . $newDir . $item . ' && ln -fs ' . $repoBaseDir . '/shared' . $item . ' ' . $repoBaseDir . '/releases/' . $newDir . $item);
            }
        }

        if (isset($cmds['commands']) && is_array($cmds['commands'])) {
            foreach ($cmds['commands'] as $cmd) {
                $systemTools->executeCommand($cmd, true);
            }
        }

        if (is_array($cmds['release'])) {
            if ($url) {
                if (isset($cmds['release']['keep']) && is_numeric($cmds['release']['keep']) && $cmds['release']['keep'] > 0) {
                    $dirs = glob($repoBaseDir . '/releases/*', GLOB_ONLYDIR);
                    rsort($dirs);
                    $i = 0;
                    foreach ($dirs as $dir) {
                        $i++;
                        if ($i > $cmds['release']['keep']) {
                            $output->writeln('Removing extra release ' . basename($dir));
                            $systemTools->executeCommand('rm -Rf ' . $dir);
                        }
                    }
                }
                $output->writeln('Linking release ' . $newDir);
                $systemTools->executeCommand('ln -sf ' . $repoBaseDir . '/releases/' . $newDir . ' ' . $repoBaseDir . '/releases/current && mv ' . $repoBaseDir . '/releases/current ' . $repoBaseDir . '/');
            } elseif (isset($cmds['release']['standalone']) && is_array($cmds['release']['standalone'])) {
                foreach ($cmds['release']['standalone'] as $cmd) {
                    $systemTools->executeCommand($cmd);
                }
            }
            if (isset($cmds['release']['after']) && is_array($cmds['release']['after'])) {
                foreach ($cmds['release']['after'] as $cmd) {
                    $systemTools->executeCommand($cmd);
                }
            }
        }

        $config = ConfigTools::getLocalConfig([
            'after' => []
        ]);

        if (is_array($config) && isset($config['after']) && is_array($config['after'])) {
            foreach ($config['after'] as $cmd) {
                $systemTools->executeCommand($cmd, true);
            }
        }

        if (!$silent && is_array($cmds['release']) && null !== $outputFile) {
            $config = ConfigTools::getLocalConfig([
                'email' => [
                    'sender' => null,
                    'address' => null,
                    'host' => null,
                    'port' => 25,
                    'user' => null,
                    'password' => null,
                ]
            ]);

            $outputResult = $systemTools->cleanAnsiColors(file_get_contents($outputFile));

            exec('git log -1 --pretty=%B', $sysOutput);
            $lastCommit = trim(implode("\n", $sysOutput));

            if ($pullBranch && isset($yaml['pulls']) && is_array($yaml['pulls']) && isset($yaml['pulls']['url'])) {
                $liveUrl = sprintf($yaml['pulls']['url'], $pullDir);
            } else {
                $liveUrl = $cmds['release']['url'];
            }

            ServiceTools::sendGitHubStatus('success', $liveUrl, 'Staging environment has been updated.');
            $systemTools->removeLockfile($repoBaseDir);

            if (isset($yaml['emails']) && is_array($yaml['emails'])) {
                if (!empty($config['email']['host'])) {
                    $transport = new \Swift_SmtpTransport($config['email']['host'], $config['email']['port']);
                    if (!empty($config['email']['user'])) {
                        $transport->setUsername($config['email']['user']);
                    }
                    if (!empty($config['email']['password'])) {
                        $transport->setPassword($config['email']['password']);
                    }
                } else {
                    $transport = new \Swift_SmtpTransport();
                }

                $mailer = new \Swift_Mailer($transport);

                $converter = new AnsiToHtmlConverter();
                $html = $converter->convert(file_get_contents($outputFile));

                $message = (new \Swift_Message(($systemTools->hasErrorReturned() ? '🔴 ' : null) . 'WebHook ' . $cmds['release']['name']))
                    ->setFrom(array($config['email']['address'] => $config['email']['sender']))
                    ->setTo($yaml['emails'])
                    ->setBody('<html><body><pre style="background-color: black; overflow: auto; padding: 10px 15px; font-family: monospace;">' . $html . '</pre></body></html>', 'text/html')
                    ->addPart($outputResult, 'text/plain');
                $result = $mailer->send($message);

                $output->writeln('Email sent: '.$result);
            }

            if (
                (isset($yaml['slack']) && is_array($yaml['slack']) && !empty($yaml['slack']['url']) && !empty($yaml['slack']['channel'])) ||
                (isset($cmds['release']['slack']) && is_array($cmds['release']['slack']) && !empty($cmds['release']['slack']['url']) && !empty($cmds['release']['slack']['channel']))
            ) {
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
                    $actions = [
                        [
                            'type' => 'button',
                            'text' => 'Go to URL',
                            'url' => $liveUrl,
                        ]
                    ];

                    if ($pullBranch) {
                        $title = 'Pull Request from ' . $pullBranch;
                        $actions[] = [
                            'type' => 'button',
                            'text' => 'Go to PR',
                            'url' => 'https://github.com/'.str_replace('.git', '', substr($url, strpos($url, ':')+1)).'/pull/'.$pullId,
                        ];
                    } else {
                        $title = 'Release from ' . $branch;
                    }

                    $slackUrl = isset($cmds['release']['slack']) ? $cmds['release']['slack']['url'] : $yaml['slack']['url'];
                    $slackChannel = isset($cmds['release']['slack']) ? $cmds['release']['slack']['channel'] : $yaml['slack']['channel'];

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $slackUrl);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'gonetcats/hooks');
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'attachments' => [
                            [
                                'title' => $title,
                                'text' => $lastCommit,
                                'fallback' => 'Go to URL: '.$liveUrl,
                                'actions' => $actions,
                            ]
                        ]
                    ]));

                    $data = curl_exec($ch);
                    curl_close($ch);

                    $output->writeln('Slack message sent: '.$data);
                }
            }
        }

        return null;
    }
}
