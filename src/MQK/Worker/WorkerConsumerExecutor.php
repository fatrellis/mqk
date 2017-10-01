<?php
namespace MQK\Worker;


use Monolog\Logger;
use MQK\Error\ErrorHandler;
use MQK\Exception\TestTimeoutException;
use MQK\ExpiredFinder;
use MQK\Health\HealthReporter;
use MQK\Health\WorkerHealth;
use MQK\LoggerFactory;
use MQK\Queue\MessageInvokableSync;
use MQK\Queue\MessageInvokableSyncController;
use MQK\Queue\RedisQueueCollection;
use MQK\Registry;

class WorkerConsumerExecutor
{
    /**
     * @var QueueCollection
     */
    protected $queues;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $burst = false;

    /**
     * @var bool
     */
    protected $fast = false;

    /**
     * @var ExpiredFinder
     */
    protected $expiredFindder;

    /**
     * @var MessageInvokableSyncController
     */
    protected $messageInvokableSyncController;

    /**
     * @var WorkerHealth
     */
    protected $health;

    /**
     * @var HealthReporter
     */
    protected $healthRepoter;

    /**
     * @var int
     */
    protected $consumed = 0;

    /**
     * @var ErrorHandler[]
     */
    protected $errorHandlers = [];

    /**
     * @var boolean
     */
    protected $isSearchExpiredMessage = false;

    /**
     * WorkerConsumerExecutor constructor.
     *
     * @param boolean $burst
     * @param boolean $fast
     * @param RedisQueueCollection $queues
     * @param Registry $registry
     * @param MessageInvokableSyncController $messageInvokableSyncController
     * @param HealthReporter $healthReporter
     * @param ErrorHandler[] $errorHandlers
     */
    public function __construct(
        $burst,
        $fast,
        RedisQueueCollection $queues,
        Registry $registry,
        $expiredFinder,
        MessageInvokableSyncController $messageInvokableSyncController,
        WorkerHealth $workerHealth,
        HealthReporter $healthReporter,
        $errorHandlers,
        $isSearchExpiredMessage) {

        $this->burst = $burst;
        $this->fast = $fast;
        $this->queues = $queues;
        $this->registry = $registry;
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
        $this->expiredFindder = $expiredFinder;
        $this->messageInvokableSyncController = $messageInvokableSyncController;
        $this->healthRepoter = $healthReporter;
        $this->errorHandlers = $errorHandlers;
        $this->isSearchExpiredMessage = $isSearchExpiredMessage;
    }

    public function execute()
    {
        $this->healthRepoter->report(WorkerHealth::STARTED);

        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);

        $this->logger->debug("Watch queue list " . join(", ", $this->queueNameList));
        $this->workerStartTime = Time::micro();
        while ($this->alive) {
            try {
                $this->healthRepoter->report(WorkerHealth::EXECUTING);
                $success = $this->consumeOneMessage();
                $this->healthRepoter->report(WorkerHealth::EXECUTED);

                if ($success)
                    $this->success += 1;

            } catch (EmptyQueueException $e) {
                $this->alive = false;
                $this->logger->info("When the burst, queue is empty worker {$this->id} will quitting.");
            }

            $memoryUsage = $this->memoryGetUsage();
            if ($memoryUsage > self::M * 1024) {
                break;
            }

//            $health->setDuration(Time::micro() - $this->workerStartTime);
        }


        $this->workerEndTime = Time::micro();
        $this->didQuit();
        exit(0);
    }

    /**
     * @return boolean 执行成功
     */
    public function consumeOneMessage()
    {
        $now  = time();

        $message = $this->queues->dequeue(!$this->burst);
        // 可能出列的数据是空
        if (null == $message) {
            return false;
        }
        $this->consumed += 1;
        $this->healthRepoter->health()->setConsumed($this->consumed);
        $this->healthRepoter->report(WorkerHealth::DID_DEQUEUE);
        $this->logger->debug("Pop a message {$message->id()} at {$now}.");
        if (!$this->fast) {
            $this->registry->start($message);
        }

        $success = true;
        try {
            $beforeExecute = time();
            $this->logger->debug('Message will execute');
            $this->healthRepoter->report(WorkerHealth::EXECUTING);
            $message();
            $this->healthRepoter->report(WorkerHealth::EXECUTED);
            if ($message instanceof MessageInvokableSync) {
                $this->messageInvokableSyncController->invoke($message);
            }

            $success = true;

            $afterExecute = time();
            $duration = $afterExecute - $beforeExecute;
            $this->logger->info("Message execute duration {$duration}");
            $messageClass = (string)get_class($message);
            $this->logger->debug("{$messageClass} {$message->id()} is finished");
            if ($afterExecute - $beforeExecute >= $message->ttl()) {
                $this->logger->warn(sprintf("The message %s timed out for %d seconds.", $message->id(), $message->ttl()));
            }

            if (!$this->fast)
                $this->registry->finish($message);

            if ($this->isSearchExpiredMessage) {
                $this->expiredFindder->process();
            }

        } catch (\Exception $exception) {
            $success = false;
            if ($exception instanceof TestTimeoutException) {
                $this->logger->debug("Catch timeout exception.");
            } else {
                foreach ($this->errorHandlers as $errorHandler)
                    $errorHandler->got($exception);
                $this->registry->fail($message);
            }
        }

        return $success;
    }

    protected function didQuit()
    {
        if (0 == $this->workerEndTime)
            $this->workerEndTime = time();
        $duration = $this->workerEndTime - $this->workerStartTime;
        $this->logger->notice("[run] duration {$duration} second");
        $this->logger->notice("Success {$this->success} failure {$this->failure}");
    }

    protected function memoryGetUsage()
    {
        return memory_get_usage(false);
    }
}