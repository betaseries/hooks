<?php

namespace Hooks\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Class WorkerIncrementCommand
 *
 * @package Hooks\Command
 */
class WorkerIncrementCommand extends Command
{
    /**
     * Configure
     */
    public function configure()
    {
        $this->setName('worker:incr');
        $this->setDescription('Increment Redis worker version.');
        $this->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Redis host.', null);
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Redis port.', null);
        $this->addOption('db', 'd', InputOption::VALUE_OPTIONAL, 'Redis database.', null);
    }

    /**
     * @param InputInterface  $input  Input
     * @param OutputInterface $output Output
     *
     * @return null
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $redisHost = $input->getOption('host');
        $redisPort = $input->getOption('port');
        $redisDb = $input->getOption('db');

        try {
            $config = Yaml::parse(file_get_contents($_SERVER['HOME'] . '/.hooks.yml'));
        } catch (\Exception $e) {
            $config = [
                'daemon' => [
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'db' => 0,
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

        $redis = new \Redis();
        $redis->connect($config['daemon']['host'], $config['daemon']['port']);
        $redis->select($config['daemon']['db']);

        $version = $redis->incr('hooks.worker.version');

        $output->writeln('<info>Worker version incremented: ' . $version . '.</info>');

        return null;
    }
}