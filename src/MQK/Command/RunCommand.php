<?php
namespace MQK\Command;

use Monolog\Logger;
use MQK\Config;
use MQK\LoggerFactory;
use MQK\OSDetect;
use MQK\Queue\Message\MessageDAO;
use MQK\Queue\MessageInvokableSyncController;
use MQK\Queue\RedisQueue;
use MQK\Queue\RedisQueueCollection;
use MQK\RedisProxy;
use MQK\Registry;
use MQK\Runner\PosixRunner;
use MQK\Runner\WindowsRunner;
use MQK\SearchExpiredMessage;
use MQK\Worker\ConsumerExecutorWorkerFactory;
use MQK\Worker\ConsumerWorkerFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends AbstractCommand
{
    public function __construct($name = null)
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName("run")
            ->addOption("concurrency", 'c', InputOption::VALUE_OPTIONAL, "", 1)
            ->addOption("redis", "s", InputOption::VALUE_OPTIONAL)
            ->addOption("burst", 'b', InputOption::VALUE_NONE)
            ->addOption("quite", '', InputOption::VALUE_NONE)
            ->addOption("fast", 'f', InputOption::VALUE_NONE)
            ->addOption("test", 't', InputOption::VALUE_OPTIONAL)
            ->addOption("empty-worker", '', InputOption::VALUE_NONE)
            ->addOption("config", '', InputOption::VALUE_OPTIONAL, "", "")
            ->addOption("sentry", '', InputOption::VALUE_OPTIONAL, '', '')
            ->addOption('bootstrap', '', InputOption::VALUE_OPTIONAL)
            ->addOption('queue', '', InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED)
            ->addOption('retry', 'r', InputOption::VALUE_OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $burst = $input->getOption("burst");

        LoggerFactory::shared()->setDefaultLevel(Logger::NOTICE);

        $config = Config::defaultConfig();
        $config->setBurst($burst);

        $fast = $input->getOption("fast");
        if ($fast)
            $config->enableFast();

        $max = (int)$input->getOption("test");
        if ($max > 0)
            $config->setTestJobMax($max);

        $retry = $input->getOption('retry');
        if (!empty($retry) && is_integer($retry)) {
            $retry = (int)$retry;
            $config->setMaxRetries($retry);
        }

        $bootstrap = $input->getOption('bootstrap');
        if (!empty($bootstrap))
            $config->setBootstrap($bootstrap);

        parent::execute($input, $output);
        $this->start($config);
    }

    public function start(Config $config)
    {
        // Objects
        $burst = $config->burst();
        $fast = $config->fast();

        $connection = new RedisProxy($config->redis());
        $messageDAO = new MessageDAO($connection);
        $queue = new RedisQueue($connection, $config->queuePrefix());
        $registry = new Registry($connection);
        $queues = new RedisQueueCollection($connection, $config->queues());

        $searchExpiredMessage = new SearchExpiredMessage(
            $connection,
            $messageDAO,
            $registry,
            $queue,
            $config->maxRetries()
        );

        $messageController = new MessageInvokableSyncController(
            $connection,
            $queue,
            $messageDAO
        );

        $consumerExecutorFactory = new ConsumerExecutorWorkerFactory(
            $burst,
            $fast,
            $connection,
            $registry,
            $queues,
            $searchExpiredMessage,
            $messageController,
            $config->errorHandlers()
        );

        $workerFactory = new ConsumerWorkerFactory(
            $config->bootstrap(),
            $connection,
            $consumerExecutorFactory
        );

        $osDetect = new OSDetect();
        if ($osDetect->isPosix()) {
//        if (false) {
            $runnerClass = PosixRunner::class;
        } else {
            $runnerClass = WindowsRunner::class;
        }

        $runner = new $runnerClass(
            $burst,
            $fast,
            $config->concurrency(),
            $workerFactory,
            $connection,
            $searchExpiredMessage
        );

        $runner->run();
    }
}