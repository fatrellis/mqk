<?php
namespace MQK;
use MQK\Exception\JobMaxRetriesException;
use MQK\Job\JobDAO;
use MQK\MasterProcess\MasterProcess;
use MQK\Queue\MessageAbstractFactory;
use MQK\Queue\Queue;
use MQK\Queue\QueueCollection;
use MQK\Queue\QueueFactory;
use MQK\Queue\RedisQueue;
declare(ticks=1);

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use MQK\Queue\RedisQueueCollection;
use MQK\Worker\Worker;
use MQK\Worker\WorkerConsumer;
use MQK\Worker\WorkerConsumerFactory;
use MQK\Worker\WorkerFactory;


class Runner implements MasterProcess
{
    private $config;
    private $workers = [];

    /**
     * @var \Redis
     */
    private $connection;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var JobDAO
     */
    private $jobDAO;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Logger
     */
    private $cliLogger;

    /**
     * @var QueueCollection
     */
    private $queues;

    private $alive = true;

    private $exists = 0;

    private $nameList = ['default'];

    /**
     * @var WorkerFactory
     */
    protected $workerFactory;

    protected $findExpiredJob = true;

    /**
     * @var ExpiredFinder
     */
    protected $expiredFinder;

    /**
     * @var PIPE
     */
    protected $selfPipe;

    protected $signalList = [];

    protected $quiting = false;
    protected $quited = 0;

    protected $spawning = false;

    /**
     * @var string
     */
    protected $masterId;

    public function __construct()
    {
        $redisFactory = RedisFactory::shared();
        try {
            $this->connection = $redisFactory->createConnection();
        } catch (\RedisException $e) {
            if ("Failed to AUTH connection" == $e->getMessage()) {
                $this->cliLogger->error($e->getMessage());
                exit(1);
            }
        }

        $queueFactory = new QueueFactory($this->connection, new MessageAbstractFactory());
        $config = Config::defaultConfig();
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
        $this->cliLogger = LoggerFactory::shared()->cliLogger();

        $this->config = $config;
        $this->registry = new Registry($this->connection);
        $this->jobDAO = new JobDAO($this->connection);

        $this->queues = new RedisQueueCollection(
            $this->connection,
            $queueFactory->createQueues($this->nameList, $this->connection)
        );
        $queues = ["default"];

        $this->selfPipe = new PIPE();
        $this->workerFactory = new WorkerConsumerFactory($config, $queues);

        $this->expiredFinder = new ExpiredFinder($this->connection, $this->jobDAO, $this->registry, $this->queues);
    }

    function signalQuitHandler($signo)
    {
    }

    function signalChildHandler($status)
    {
        $this->logger->debug("Received SIGCHLD signal.");
        while (-1 != pcntl_waitpid(0, $status)) {
            pcntl_wexitstatus($status);
            $this->exists += 1;
        }

        $allChildrenProcessQuited = $this->config->workers() == $this->exists;
        if ($allChildrenProcessQuited and $this->config->burst()) {
            $this->alive = false;
            return;
        }

        if (!$this->config->burst() && !$this->quiting) {
            $this->spawn();
        }
    }

    function sigintHandler($signo)
    {
        if ($this->quiting) {
            $this->logger->debug("Force quit.");
            exit(0);
        }
        $this->quiting = true;
        $this->logger->debug("Weakup signal.");
        $this->selfPipe->write(".");
//        $this->stop(true);
    }

    function signalIncrement($status)
    {
        $this->spawn();
    }

    public function run()
    {
        $this->masterId = uniqid();
        $this->cliLogger->notice("MasterProcess ({$this->masterId}) work on " . posix_getpid());
        $this->logger->debug("Starting {$this->config->workers()} workers.");

        for ($i = 0; $i < $this->config->workers(); $i++) {
            $worker = $this->spawn();
        }

        pcntl_signal(SIGCHLD, array(&$this, "signalChildHandler"));
        pcntl_signal(SIGINT, array(&$this, "sigintHandler"));
//        pcntl_signal(SIGQUIT, array(&$this, 'signalQuitHandler'));

        $fast = $this->config->fast();
        $findExpiredJob = $this->findExpiredJob;

        $buffer = null;
        while ($this->alive) {
            try {
                $buffer = $this->selfPipe->read();
            } catch (\Exception $e) {
                // 被信号唤醒
                $this->logger->error($e->getMessage());
                $this->stop(true);
//                continue;
            }
            $this->updateHealth();
            if (!$fast && $findExpiredJob) {
                $this->expiredFinder->process();
            }

        }
    }

    function spawn()
    {
        $worker = $this->workerFactory->create($this->masterId);
        $pid = $worker->start();
        $worker->setId($pid);
        $this->workers[$worker->id()] = $worker;

        $this->logger->debug("Started new worker {$worker->id()}");
        return $worker;
    }

    function halt()
    {
        // kill all process
        /**
         * @var $worker Worker
         */
        foreach ($this->workers as $worker) {
            $this->cliLogger->info("Killing process {$worker->id()}");
            if (!posix_kill($worker->id(), SIGUSR1)) {
                $this->cliLogger->error("Kill process failure {$worker->id()}");
            }
        }
        $this->logger->info("MasterProcess process quit.");
        exit(0);
    }

    public function workerFactory()
    {
        return $this->workerFactory;
    }

    public function setWorkerFactory($workerFactory)
    {
        $this->workerFactory = $workerFactory;
    }


    public function stop($graceful = false)
    {
        $this->quiting =  true;
        $signal = $graceful ? SIGTERM : SIGQUIT;
        $limit = time() + 5;
        $this->killall($signal);

        while (time() < $limit) {
            usleep(100000);
        }
        $this->killall(SIGQUIT);

        $this->logger->info("MasterProcess process quit.");
        exit(0);
    }

    protected function killall($signal)
    {
        $signalAction = $signal == SIGTERM ? "exit" : "quit";
        /**
         * @var $worker Worker
         */
        foreach ($this->workers as $worker) {
            $this->cliLogger->info("{$signalAction} process {$worker->id()}");
            if (!posix_kill($worker->id(), $signal)) {
                $this->cliLogger->error("{$signalAction} process failure {$worker->id()}");
            }
        }
    }

    protected function updateHealth()
    {
        $key = "mqk:{$this->masterId}";
        $this->connection->multi();
        $this->connection->hSet($key, "updated_at", time());
        $this->connection->expire($key, 5);
        $this->connection->exec();
    }

}