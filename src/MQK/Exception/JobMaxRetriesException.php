<?php
namespace MQK\Exception;


use MQK\CallableJob;
use Throwable;

class JobMaxRetriesException extends \Exception
{
    /**
     * @var CallableJob
     */
    private $job;

    public function __construct(CallableJob $job, Throwable $previous = null)
    {
        $this->job = $job;
        parent::__construct("", 0, $previous);
    }

    public function job()
    {
        return $this->job;
    }
}