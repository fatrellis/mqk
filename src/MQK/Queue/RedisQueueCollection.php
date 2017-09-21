<?php
namespace MQK\Queue;

use Monolog\Logger;
use MQK\LoggerFactory;
use MQK\RedisFactory;
use MQK\RedisProxy;

class RedisQueueCollection implements QueueCollection
{
    /**
     * @var RedisProxy
     */
    private $connection;

    /**
     * @var string[]
     */
    private $queues;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * @var MessageAbstractFactory
     */
    private $messageFactory;

    const QUEUE_KEY_PREFIX = "queue";

    /**
     * RedisQueueCollection constructor.
     * @param $connection \Redis
     */
    public function __construct($connection, $queues)
    {
        $this->connection = $connection;
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
        $this->messageFactory = new MessageAbstractFactory();

        $this->queues = array_map(function($queue) {
            return self::QUEUE_KEY_PREFIX . "_" . $queue;
        }, $queues);
    }

    public function dequeue($block=true)
    {
        $messageJsonObject = $this->connection->listPop($this->queues, $block, 1);

        if (null == $messageJsonObject)
            return null;

        try {
            $messageJsonObject = json_decode($messageJsonObject);
//            $this->logger->debug("[dequeue] {$jsonObject->id}");
//            $this->logger->debug($messageJsonObject);
            // 100k 对象创建大概300ms，考虑是否可以利用对象池提高效率

            $message = $this->messageFactory->messageWithJson($messageJsonObject);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $message = null;
        }
//        if (null == $job) {
//            $this->logger("Make job object error.", $raw);
//            throw \Exception("Make job object error");
//        }
        return $message;
    }
}