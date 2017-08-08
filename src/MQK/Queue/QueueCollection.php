<?php
namespace MQK\Queue;


interface QueueCollection
{
    /**
     * 出队列
     *
     * @param boolean block
     *
     * @return Message
     */
    function dequeue($block=true);
}