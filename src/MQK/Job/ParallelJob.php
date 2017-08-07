<?php
namespace MQK\Job;

/**
 *
 * Class ParallerJob
 * @package MQK\Job
 */
class ParallelJob extends FunctionJob
{
    /**
     * @var 并发观察者
     */
    private $parallelWatcher;
}