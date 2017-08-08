<?php
namespace MQK\Queue;

use MQK\CallableJob;

/**
 * 队列接口
 * 
 * 默认使用Redis实现队列，将来可能会增加RabbitMQ和SQS
 */
interface Queue
{
    /**
     * 进入队列
     *
     * @param Message $message
     * @return void
     */
    function enqueue(Message $message);

    function name();

    function key();
}