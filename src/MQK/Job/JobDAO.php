<?php
namespace MQK\Job;

use Connection\RedisConnectionProxy;
use Monolog\Logger;
use MQK\CallableJob;
use MQK\LoggerFactory;

class JobDAO
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var \Redis
     */
    private $connection;

    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
    }

    /**
     * @param $id
     * @return CallableJob
     */
    public function find($id)
    {
        $raw = $this->connection->get("job:{$id}");
        if (null == $raw || false === $raw) {
            $this->logger->error("Job {$id} not found.");
            throw new \Exception("Job {$id} not found.");
        }
        $jsonObject = json_decode($raw);
        return CallableJob::job($jsonObject);
    }

    public function store(CallableJob $job)
    {
        $raw = json_encode($job->jsonSerialize());
        $this->connection->set("job:", $job->id(), $raw);
    }

    public function clear($job)
    {
        $this->connection->hDel("job", $job->id());
    }
}