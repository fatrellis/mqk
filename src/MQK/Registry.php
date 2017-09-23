<?php
namespace MQK;

use Monolog\Logger;
use MQK\Queue\Message\MessageDAO;
use MQK\Queue\Message;

class Registry
{
    /**
     * @var RedisProxy
     */
    private $connection;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Config
     */
    private $config;

    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
        $this->config = Config::defaultConfig();
    }

    public function setConnection($connection)
    {
        $this->connection = $connection;
    }

    public function start(Message $message)
    {
        if (strpos($message->id(), "_") > -1) {
            $this->logger->debug("Name of message is invalid.");
            return;
        }
        $ttl = time() + $message->ttl();
        $messageJsonObject = $message->jsonSerialize();

        $this->logger->debug("Message {$message->id()} set expire date to $ttl)", $messageJsonObject);

        if (empty($this->config->cluster()))
            $this->connection->multi();

        $this->connection->zAdd("mqk:started", $ttl, $message->id());
        $this->connection->set("mqk:message:{$message->id()}", json_encode($messageJsonObject), 108000);

        if (empty($this->config->cluster()))
            $this->connection->exec();
    }

    public function fail(Message $message)
    {
        $ttl = time() + $message->ttl();
        $this->connection->zAdd("mqk:fail", $ttl, $message->id());
    }

    public function finish(Message $message)
    {
//        $this->connection->zAdd("mqk:finished", $ttl, $job->id());
        $this->connection->zDelete("mqk:started", $message->id());
    }

    public function clear($queueName, $id)
    {
        $return = $this->connection->zDelete($queueName, $id);
    }

    public function queryExpiredMessage($queueName)
    {
        $now = time();
        $id = $this->connection->zRangeByScore(
            $queueName,
            0,
            $now,
            array("limit" => array(0, 1)));
        if (is_array($id)) {
            if (empty($id))
                return null;
            $id = $id[0];
        }

        if (empty($id))
            return null;

        $this->logger->debug("Found expire ($now) message {$id}.");
        return $id;
    }

}