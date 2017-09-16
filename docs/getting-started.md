新手入门
========

异步RPC
-------

1. 新增composer包

```php
$ composer require fatrellis/mqk
```

2. 定义异步RPC方法
```php
class Calculator
{
    public static function sum($a, $b)
    {
        return $a + $b;
    }
}
```

3. 启动消费者进程

-vvv 参数打印详细日志

```php
$ vendor/bin/mqk run -vvv
```

4. 执行异步RPC

```php
\K::invoke('Calculator::sum', 1, 2);
```

更多
----

[事件](event.md)