<?php
namespace MQK\Queue;


use MQK\Queue\Message\MessageDAO;
use MQK\LoggerFactory;
use MQK\RedisProxy;

class Invokes
{
    /**
     * @var Invoke[]
     */
    private $invokes;
    private $id;

    /**
     * @var RedisProxy
     */
    private $connection;
    private $logger;

    /**
     * @var MessageDAO
     */
    private $messageDAO;

    private $waited = false;

    private $queue;

    public function __construct(...$args)
    {
        $this->id = uniqid();
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
        $this->invokes = $args;
        foreach ($args as $invoke)
            $invoke->setInvokes($this);
    }

    public function id()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function invokes()
    {
        return $this->invokes;
    }

    public function length()
    {
        return count($this->invokes);
    }

    public function connection()
    {
        return $this->connection;
    }

    public function setConnection($connection)
    {
        $this->connection = $connection;
    }

    public function messageDAO()
    {
        return $this->messageDAO;
    }

    public function setMessageDAO($messageDAO)
    {
        $this->messageDAO = $messageDAO;
    }

    public function wait()
    {
        $raw = $this->connection->blpop("queue_" . $this->id, 10);

        foreach ($this->invokes() as $invoke) {
            /**
             * @var Invoke $invoke
             */
            $message = $this->messageDAO->find($invoke->id());
            $invoke->setMessage($message);
        }

        $this->waited = true;
    }

    public function returns()
    {
        if (!$this->waited)
            $this->wait();

        $returns = [];
        foreach ($this->invokes() as $invoke) {
            $returns[$invoke->key()] = $invoke->message()->returns();
        }

        return $returns;
    }

    /**
     * @return mixed
     */
    public function queue()
    {
        return $this->queue;
    }

    /**
     * @param mixed $queue
     */
    public function setQueue($queue)
    {
        $this->queue = $queue;

        foreach ($this->invokes as $invoke) {
            $invoke->setQueue($queue);
        }
    }
}