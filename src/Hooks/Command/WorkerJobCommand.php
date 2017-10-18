<?php

namespace Hooks\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WorkerJobCommand
 *
 * @package Hooks\Command
 */
class WorkerJobCommand extends Command
{
    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('worker:job');
        $this->setDescription('Redis worker.');
        $this->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Redis host.', null);
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Redis port.', null);
        $this->addOption('db', 'd', InputOption::VALUE_OPTIONAL, 'Redis database.', null);
        $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Redis queue.', null);
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
        $redisHost = $input->getOption('host');
        $redisPort = $input->getOption('port');
        $redisDb = $input->getOption('db');
        $redisQueue = $input->getOption('queue');

        try {
            $config = Yaml::parse(file_get_contents($_SERVER['HOME'] . '/.hooks.yml'));
        } catch (\Exception $e) {
            $config = [
                'daemon' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'db' => 0,
                    'queue' => 'jobs',
                ]
            ];
        }

        if ($redisHost) {
            $config['daemon']['host'] = $redisHost;
        }
        if ($redisPort) {
            $config['daemon']['port'] = $redisPort;
        }
        if ($redisDb) {
            $config['daemon']['db'] = $redisDb;
        }
        if ($redisQueue) {
            $config['daemon']['queue'] = $redisQueue;
        }

        ini_set('default_socket_timeout', -1);

        $redis = new \Redis();
        $redis->connect($config['daemon']['host'], $config['daemon']['port']);
        if (defined('\Redis::OPT_READ_TIMEOUT')) {
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 31);
        }
        $redis->select($config['daemon']['db']);

        $timeout = time() + 60 * 60 * 1 + rand(0, 60 * 10);
        $version = $redis->get('hooks.worker.version');

        while (time() < $timeout) {
            $job = $redis->blpop(['hooks.worker.' . $config['daemon']['queue']], 10);

            if ($job) {
                $details = json_decode($job[1], true);
                try {
                    $argsCli = null;
                    foreach ($details['args'] as $arg => $value) {
                        $argsCli .= $arg . '=' . $value . ' ';
                    }
                    $output->writeln('<info>[' . date('c') . '] New job started: ' . $details['command'] . ' ' . trim($argsCli) . '</info>');

                    switch ($details['command']) {
                        case 'install':
                            $command = new InstallCommand();
                            break;

                        case 'clean':
                            $command = new CleanCommand();
                            break;

                        default:
                            $command = new InstallCommand();
                            break;
                    }

                    $cmdInput = new ArrayInput($details['args']);

                    $command->run($cmdInput, $output);

                    $output->writeln('<info>[' . date('c') . '] Job successfully executed.</info>');
                } catch (\Exception $e) {
                    $output->writeln('<error>[' . date('c') . '] Error: ' . $e->getMessage() . '</error>');
                }

                return null;
            }

            if ($redis->get('hooks.worker.version') != $version) {
                $output->writeln('New version detected... Reloading.');

                return null;
            }
        }

        return null;
    }
}
