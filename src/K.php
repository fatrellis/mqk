<?php
use MQK\CallableJob;
use MQK\Queue\Queue;
use MQK\Queue\RedisQueue;

class K
{
    /**
     * @var Queue
     */
    private static $queue;

    public static function setup($config)
    {
    }

    static function job($func, $args)
    {
        $job = new CallableJob(null, $func, $args);
        $job->setConnection(self::defaultQueue()->connection());

        return $job;
    }

    public static function invoke($func, ...$args)
    {
        $job = self::job($func, $args);
        self::defaultQueue()->enqueue($job);

        return $job;
    }

    public static function delay($second, $func, ...$args)
    {
        $job = self::job($func, $args);
        $job->setDelay($second);
        self::defaultQueue()->enqueue($job);

        return $job;
    }

    static function defaultQueue()
    {
        if (null == self::$queue)
            self::$queue = (new \MQK\Queue\QueueFactory())->createQueue("default");

        return self::$queue;
    }
}