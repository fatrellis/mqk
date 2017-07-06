<?php
namespace MQK;


use AD7six\Dsn\Dsn;
use Monolog\Logger;

/**
 * Redis工厂对象
 *
 * @package MQK
 */
class RedisFactory
{
    /**
     * @var \Redis
     */
    private $connection;

    /**
     * Retry times
     * @var int
     */
    private $retires = 0;

    /**
     * @var RedisFactory
     */
    private static $shared;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var string Redis server address
     */
    private $host;

    /**
     * @var int Redis server port
     */
    private $port;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct()
    {
        $this->config = Config::defaultConfig();
        $this->logger = LoggerFactory::shared()->getLogger(__CLASS__);
    }

    public function createRedis($dsn=null)
    {
        if (null == $dsn) {
            $dsn = "redis://{$this->config->host()}:{$this->config->port()}";
        }
        $dsn = Dsn::parse($dsn);

        $this->host = $dsn->host;
        $this->port = (int)$dsn->port;

        if (null != $this->connection) {
            return $this->connection;
        }

        $redis = new \Redis();
        $this->connection = $redis;
        $this->connect();

        return $redis;
    }

    /**
     * Redis重连
     *
     * @param int $max 最大重连次数
     * @return \Redis
     */
    public function reconnect($max=3)
    {
        $this->retires += 1;
        $this->logger->info("Redis retry {$this->retires} times.");
        if ($this->retires >= $max) {
            $this->logger->info("Max retries {$max} will be quit.");
            sleep(1);
            exit(0);
        }

        $this->connection = new \Redis();
        $this->connect();
        return $this->connection;
    }

    function connect()
    {
        $this->logger->debug("Connection to redis {$this->host}.");
        $this->connection->connect($this->host);
    }

    /**
     * retry 内的方法如果出现连接断开，自动充实
     * TODO: 没有区分连接异常还是Redis的命令异常
     *
     * @param $callback
     * @param int $i 递归次数
     * @throws \RedisException
     */
    public function retry($callback, $i=0)
    {
        try {
            $callback();
        } catch (\RedisException $e) {
            $this->logger->error($e->getMessage(), $e);
            if ($i < 3)
                $this->retry($callback, $i +1);
            else
                throw $e;
        }
    }

    public static function shared()
    {
        if (!self::$shared)
            self::$shared = new RedisFactory();

        return self::$shared;
    }
}