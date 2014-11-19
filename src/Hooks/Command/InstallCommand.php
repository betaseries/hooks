<?php

namespace Hooks\Command;

use GuzzleHttp\Client;
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
    /** @var OutputInterface */
    private $_output;

    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('install');
        $this->setDescription('Install documented hooks.');
        $this->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Working directory.', '.');
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
        $this->_output = $output;

        $outputResult = null;

        $dir = $input->getOption('dir');
        $url = $input->getOption('url');
        $silent = $input->getOption('silent');
        $branch = $input->getArgument('branch');

        if (!$branch) {
            $branch = trim(substr(file_get_contents($dir . '/.git/HEAD'), 16));
            if (empty($branch)) {
                $branch = 'master';
            }
        }

        $newDir = date('YmdHis');
        $baseDir = $dir;

        chdir($baseDir);

        if ($url) {
            $outputResult .= $this->executeCommand('git clone ' . $url . ' ' . $newDir) . PHP_EOL . PHP_EOL;
            $dir .= '/' . $newDir;
            chdir($dir);
            $outputResult .= $this->executeCommand('git checkout ' . $branch) . PHP_EOL . PHP_EOL;
        }

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

        if ($url && is_array($cmds['release'])) {
            mkdir($baseDir . '/' . $cmds['release']['directory']);
            mkdir($baseDir . '/' . $cmds['release']['directory'] . '/shared');
            mkdir($baseDir . '/' . $cmds['release']['directory'] . '/releases');

            rename($baseDir . '/' . $newDir, $baseDir . '/' . $cmds['release']['directory'] . '/releases/' . $newDir);
            chdir($baseDir . '/' . $cmds['release']['directory'] . '/releases/' . $newDir);
        } elseif ($url) {
            throw new \Exception('You cannot set a Git clone URL without any release info.');
        }

        foreach ($cmds['commands'] as $cmd) {
            $outputResult .= $this->executeCommand($cmd, $output, true) . PHP_EOL . PHP_EOL;
        }

        if (is_array($cmds['release'])) {
            if ($url && isset($cmds['release']['shared']) && is_array($cmds['release']['shared'])) {
                foreach ($cmds['release']['shared'] as $item) {
                    $output->writeln('Linking shared item ' . $item);
                    $outputResult .= $this->executeCommand('rm -Rf ' . $baseDir . '/' . $cmds['release']['directory'] . '/releases/' . $newDir . $item . ' && ln -fs ' . $baseDir . '/' . $cmds['release']['directory'] . '/shared' . $item . ' ' . $baseDir . '/' . $cmds['release']['directory'] . '/releases/' . $newDir . $item) . PHP_EOL . PHP_EOL;
                }
            }
            if (isset($cmds['release']['after']) && is_array($cmds['release']['after'])) {
                foreach ($cmds['release']['after'] as $cmd) {
                    $outputResult .= $this->executeCommand($cmd) . PHP_EOL . PHP_EOL;
                }
            }
            if ($url) {
                if (isset($cmds['release']['keep']) && is_numeric($cmds['release']['keep']) && $cmds['release']['keep'] > 0) {
                    $dirs = glob($baseDir . '/' . $cmds['release']['directory'] . '/releases/*', GLOB_ONLYDIR);
                    rsort($dirs);
                    $i = 0;
                    foreach ($dirs as $dir) {
                        $i++;
                        if ($i > $cmds['release']['keep']) {
                            $output->writeln('Removing extra release ' . basename($dir));
                            $outputResult .= $this->executeCommand('rm -Rf ' . $dir) . PHP_EOL . PHP_EOL;
                        }
                    }
                }
                $output->writeln('Linking release ' . $newDir);
                $outputResult .= $this->executeCommand('rm -f ' . $baseDir . '/' . $cmds['release']['directory'] . '/current && ln -fs ' . $baseDir . '/' . $cmds['release']['directory'] . '/releases/' . $newDir . ' ' . $baseDir . '/' . $cmds['release']['directory'] . '/current') . PHP_EOL . PHP_EOL;
            } elseif (is_array($cmds['release']['standalone'])) {
                foreach ($cmds['release']['standalone'] as $cmd) {
                    $outputResult .= $this->executeCommand($cmd) . PHP_EOL . PHP_EOL;
                }
            }
        }

        if (!$silent && is_array($cmds['release'])) {
            try {
                $config = Yaml::parse(file_get_contents($_SERVER['HOME'] . '/.hooks.yml'));
            } catch (\Exception $e) {
                $config = [
                    'email' => [
                        'sender' => null,
                        'address' => null,
                    ]
                ];
            }

            exec('git log -1 --pretty=%B', $sysOutput);
            $lastCommit = trim(implode("\n", $sysOutput));

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
                $messages = [
                    'It\'s alive',
                    'Just launched',
                    'We\'re live',
                    'Shipped',
                    'Go go go',
                ];

                $randMessage = $messages[array_rand($messages)];

                $url = $cmds['release']['url'];
                $name = $cmds['release']['name'];
                $launched = $randMessage . ': <' . $url . '|' . $name . '>';

                if (empty($url)) {
                    $launched = $randMessage . ': ' . $name;
                } elseif (empty($name) && !empty($url)) {
                    $launched = $randMessage . ': <' . $url . '>';
                }

                if (!empty($url) || !empty($name)) {
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

    /**
     * @param string $cmd
     * @param bool   $displayCommand
     *
     * @return string|OutputInterface
     */
    private function executeCommand($cmd, $displayCommand=true)
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
}