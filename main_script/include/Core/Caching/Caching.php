<?php
namespace Core\Caching;
use Core\Config;
use function getWorldUniqueId;
use Redis;

class Caching
{
    private static $instance;
    private static $_self;

    public static function getInstance()
    {
        if (!(self::$_self instanceof self)) {
            self::$_self = new self();
        }
        return self::$_self;
    }

    /**
     * @return Redis
     */
    public static function singleton($key = null)
    {
        $config = Config::getInstance();
        if (!(self::$instance instanceof Redis)) {
            if(is_null($key)){
                $host = parse_url($config->settings->indexUrl, PHP_URL_HOST) ?: 'local';
                $key = trim(getWorldUniqueId() . ':' . preg_replace('/[^a-z0-9_.-]/i', '_', $host) . ':');
            }
            try {
                $redis = new Redis();
                $redis->connect(
                    getenv('REDIS_HOST') ?: 'redis',
                    (int)(getenv('REDIS_PORT') ?: 6379),
                    2.0
                );
                $password = getenv('REDIS_PASSWORD');
                if ($password !== false && $password !== '') {
                    $redis->auth($password);
                }
                $redis->setOption(Redis::OPT_PREFIX, $key);
                self::$instance = $redis;
            } catch (\Throwable $e) {
                die("Server unavailable. Please try again in a few minutes.");
            }
        }
        return self::$instance;
    }

    public function add($key, $value, $expiration = null)
    {
        if ($expiration === null) {
            return self::singleton()->set($key, serialize($value), ['nx']);
        }
        return self::singleton()->set($key, serialize($value), ['nx', 'ex' => max(1, (int)$expiration)]);
    }

    public function lock($key)
    {
        $key = 'lock_' . $key;
        return self::singleton()->setnx($key, true);
    }

    public function unlock($key)
    {
        $key = 'lock_' . $key;
        return self::singleton()->del($key);
    }

    public function returnCacheWithCallBack($key, $expire, $vars, callable $callBack)
    {
        if ($this->exists($key)) {
            return $this->get($key);
        }
        $this->set($key, call_user_func_array($callBack, $vars), $expire);
    }

    public function set($key, $value, $expiration = null)
    {
        if ($expiration === null) {
            return self::singleton()->set($key, serialize($value));
        }
        return self::singleton()->setex($key, max(1, (int)$expiration), serialize($value));
    }

    public function delete($key)
    {
        if (!is_array($key)) {
            self::singleton()->del([$key]);
        } else {
            self::singleton()->del($key);
        }
    }

    public function keys($pattern)
    {
        return self::singleton()->keys($pattern);
    }
    public function deleteByPattern($pattern)
    {
        $this->delete(self::singleton()->keys($pattern));
    }
    public function get($key)
    {
        if (!self::singleton()->exists($key)) {
            return false;
        }
        return unserialize(self::singleton()->get($key));
    }

    public function exists($key)
    {
        return self::singleton()->exists($key);
    }
}
