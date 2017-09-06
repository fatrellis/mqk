<?php
namespace MQK\Queue;

use Symfony\Component\Serializer\Serializer;
use MQK\SerializerFactory;

class MessageInvokable extends Message
{
    /**
     * 方法名
     *
     * @var string
     */
    protected $func;

    /**
     * 方法调用参数
     *
     * @var array
     */
    protected $arguments;

    /**
     * @var mixed
     */
    protected $returns;

    /**
     * @var Serializer
     */
    private $serializer;

    public function __construct($id, $discriminator = "invokable", $queue = null, $ttl = 600, $payload = null)
    {
        parent::__construct($id, $discriminator, $queue, $ttl, $payload);

        $this->func = $payload->func;
        $this->arguments = $payload->arguments;
        $this->serializer = SerializerFactory::shared()->serializer();
    }

    public function __invoke()
    {
        $arguments = $this->arguments;
        $returns = @call_user_func_array($this->func, $arguments);
        $this->returns = $returns;

        $error = error_get_last();
        error_clear_last();
//        if (!empty($error)) {
//            $this->logger->error($error['message']);
//            $this->logger->error($this->func());
//            $this->logger->error(json_encode($this->arguments()));
//
//            throw new \Exception($error['message']);
//        }

        return $returns;
    }

    public function returns()
    {
        return $this->returns;
    }

    public function setReturns($returns)
    {
        $this->returns = $returns;
    }

    public function jsonSerialize()
    {
        $payload = array(
            'func' => $this->func,
            'arguments' => $this->arguments
        );
        $json = parent::jsonSerialize();
        $json['payload'] = $payload;

        if (null != $this->returns) {
            $json['returns'] = $this->returns;
        }

        return $json;
    }
}