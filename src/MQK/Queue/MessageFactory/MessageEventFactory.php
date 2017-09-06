<?php
namespace MQK\Queue\MessageFactory;

use MQK\Queue\Message;
use MQK\Queue\MessageEvent;

class MessageEventFactory implements MessageFactory
{

    /**
     * 创建Message对象
     *
     * @param \stdClass $jsonObject
     * @return Message
     */
    public function withJsonObject($jsonObject)
    {
        $message = new MessageEvent(
            $jsonObject->id,
            $jsonObject->discriminator,
            $jsonObject->queue,
            $jsonObject->ttl,
            $jsonObject->payload
        );

        return $message;
    }
}