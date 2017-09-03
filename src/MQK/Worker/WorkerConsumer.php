<?php
declare(ticks=1);
namespace MQK\Worker;


use Monolog\Logger;
use MQK\Config;
use MQK\Exception\QueueIsEmptyException;
use MQK\Exception\JobMaxRetriesException;
use MQK\Exception\TestTimeoutException;
use MQK\Job\JobDAO;
use MQK\LoggerFactory;
use MQK\PIPE;
use MQK\Queue\Queue;
use MQK\Queue\QueueCollection;
use MQK\Queue\RedisQueue;
use MQK\Queue\RedisQueueCollection;
use MQK\Queue\TestQueueCollection;
use MQK\RedisFactory;
use MQK\Registry;
use MQK\Time;

/**
 * Woker的具体实现，在进程内调度Queue和Job完成具体任务
 *
 * Class WorkerConsumer
 * @package MQK\Worker
 */
class WorkerConsumer extends WorkerConsumerExector implements Worker
{
    protected $config;
    protected $queue;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Logger
     */
    protected $cliLogger;



    /**
     * @var QueueCollection
     */
    protected $queues;

    /**
     * @var string[]
     */
    protected $queueNameList;

    /**
     * @var float
     */
    protected $workerStartTime;

    /**
     * @var float
     */
    protected $workerEndTime;

    /**
     * @var int
     */
    protected $success = 0;

    /**
     * @var int
     */
    protected $failure = 0;

    public function __construct(Config $config, $queues)
    {
        parent::__construct($config, $queues);

        $this->loadUserInitializeScript();
    }

    public function run()
    {
        parent::run();

        $this->logger->debug("Process {$this->id} started.");
        $this->workerStartTime = Time::micro();

        while ($this->alive) {
            $this->execute();

            $memoryUsage = $this->memoryGetUsage();
            if ($memoryUsage > self::M * 1024) {
                break;
            }
        }
        $this->logger->debug("[run] Sent quit command.");

        $this->workerEndTime = Time::micro();
        $this->didQuit();
        exit(0);
    }

    protected function didQuit()
    {
        if (0 == $this->workerEndTime)
            $this->workerEndTime = time();
        $duration = $this->workerEndTime - $this->workerStartTime;
        $this->logger->notice("[run] duration {$duration} second");
        $this->logger->notice("Success {$this->success} failure {$this->failure}");
    }

    protected function willExit()
    {
    }

    protected function memoryGetUsage()
    {
        return memory_get_usage(false);
    }

    protected function loadUserInitializeScript()
    {
        if ($this->config->initScript()) {
            if (file_exists($this->config->initScript())) {
                include_once $this->config->initScript();
                return;
            } else {
//                $this->cliLogger->warning("You specify init script [{$this->config->initScript()}], but file not exists.");
            }
        }
        $cwd = getcwd();
        $initFilePath = "{$cwd}/init.php";

        if (file_exists($initFilePath)) {
            include_once $initFilePath;
        } else {
//            $this->cliLogger->warning("{$initFilePath} not found, all event will miss.");
        }
    }
}