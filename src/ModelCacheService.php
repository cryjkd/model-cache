<?php

declare(strict_types=1);
/**
 * This file is part of cryjkd.
 *
 * @github   https://github.com/cryjkd
 */

namespace Cryjkd\ModelCache;

use Hyperf\Context\Context;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Annotation\AbstractAnnotation;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Redis\RedisFactory;
use Hyperf\Utils\Str;

class ModelCacheService
{
    public const NIL_VALUE = 'NIL_VALUE';

    public const NIL_KEY = 'NIL_KEY';

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    protected $redis;

    public function __construct(StdoutLoggerInterface $logger, RedisFactory $redis)
    {
        $this->logger = $logger;
        $this->redis = $redis;
    }

    public function getCacheableValue($annotation, string $className, string $method, array $arguments): string
    {
        $annotation = $this->getAnnotation($annotation, $className, $method);
        $key = self::format($annotation->prefix, $arguments, $annotation->value);
        if (strlen($key) > 64) {
            throw new \RuntimeException('The cache key length is too long. The key is ' . $key);
        }

        return $key;
    }

    public function getCache($key, $isList, $group, $useContext = true)
    {
        $res = [];
        if ($useContext) {
            $res = $this->getContext($key);
        }

        if (! $res) {
            $res = $this->getRedisData($key, $isList, $group);
            if ($useContext) {
                $this->setContext($key, $res);
            }
        }
        return $res;
    }

    public function updateCache($key, $value, $subKey, $isList, $group, $ttl, $useContext = true)
    {
        $this->updateRedisData($key, $value, $subKey, $isList, $group, $ttl);
        if ($useContext) {
            $this->setContext($key, $value);
        }
    }

    public function setCache($key, $value, $increment, $subKey, $isList, $ttl, $fillable, $group, $useContext = true)
    {
        $redis = $this->redis->get($group);
        if ($redis->ttl($key) >= 50) { // 防止数据更新时过期导致数据丢失
            $newData = $this->setRedisData($key, $value, $increment, $subKey, $isList, $group, $ttl, $fillable, $useContext);
            if ($useContext) {
                $this->setContext($key, $newData);
            }
        } else {
            $this->destroyCache($key, $group);
        }
    }

    public function delCache($key, $subKeys, $isList, $group, $useContext = true)
    {
        $this->delRedisData($key, $subKeys, $isList, $group);
        if ($useContext) {
            $this->delContext($key);
        }
    }

    public function destroyCache($key, $group)
    {
        $this->redis->get($group)->del($key);
        $this->delContext($key);
    }

    private function getAnnotation(string $annotation, string $className, string $method): AbstractAnnotation
    {
        $collector = AnnotationCollector::get($className);
        $result = $collector['_m'][$method][$annotation] ?? null;
        if (! $result instanceof $annotation) {
            throw new \RuntimeException(sprintf('Annotation %s in %s:%s not exist.', $annotation, $className, $method));
        }

        return $result;
    }

    private static function format(string $prefix, array $arguments, ?string $value = null): string
    {
        if ($value !== null) {
            if ($matches = self::parse($value)) {
                foreach ($matches as $search) {
                    $k = str_replace(['#{', '}'], '', $search);
                    $v = (string) data_get($arguments, $k);
                    $value = Str::replaceFirst($search, $v, $value);
                    if (! $v) {
                        $value = rtrim($value, ':');
                    }
                }
            }
        } else {
            $value = implode(':', $arguments);
        }

        return $prefix . ':' . $value;
    }

    /**
     * Parse expression of value.
     */
    private static function parse(string $value): array
    {
        preg_match_all('/\#\{[\w\.]+\}/', $value, $matches);

        return $matches[0] ?? [];
    }

    /* Redis */
    private function getRedisData($key, $isList, $group)
    {
        $redis = $this->redis->get($group);
        if ($isList) {
            $res = $redis->hgetall($key);
            if (! $res) {
                return [];
            }
            if (isset($res[self::NIL_KEY]) && $res[self::NIL_KEY] == self::NIL_VALUE) {
                return self::NIL_VALUE;
            }
            foreach ($res as $k => $v) {
                $res[$k] = json_decode($v, true);
            }
        } else {
            $res = $redis->get($key);
            if (! $res) {
                return [];
            }
            if ($res != self::NIL_VALUE) {
                $res = $res ? json_decode($res, true) : [];
            }
        }

        return $res;
    }

    private function updateRedisData($key, $value, $subKey, $isList, $group, $ttl)
    {
        $redis = $this->redis->get($group);
        $ttl = $this->getTTl($ttl);
        if ($value == self::NIL_VALUE) {
            if ($isList) {
                $redis->hSet($key, self::NIL_KEY, self::NIL_VALUE);
                $redis->expire($key, $ttl);
            } else {
                $redis->set($key, self::NIL_VALUE, $ttl);
            }
        } else {
            if ($isList) {
                $new = [];
                if (! $this->isTwoDimensionalArrayWithFilter($value)) {
                    $value = [$value];
                }
                foreach ($value as $v) {
                    $k = $v[$subKey];
                    $new[$k] = json_encode($v);
                }

                if ($new && count($new) > 0) {
                    $redis->hmSet($key, $new);
                    $redis->expire($key, $ttl);
                }
            } else {
                $redis->set($key, json_encode($value), $ttl);
            }
        }
    }

    private function setRedisData($key, $value, $increment, $subKey, $isList, $group, $ttl, $fillable, $useContext): array
    {
        $incrementKey = $increment[0] ?? '';
        $incrementValue = $increment[1] ?? 0;
        $redis = $this->redis->get($group);
        $ttl = $this->getTTl($ttl);
        $res = $this->getCache($key, $isList, $group, $useContext);
        $isNull = $res == self::NIL_VALUE;
        $res = $res == self::NIL_VALUE ? [] : $res;
        if ($isList) {
            $new = [];
            if (! $this->isTwoDimensionalArrayWithFilter($value)) {
                $value = [$value];
            }
            foreach ($value as $v) {
                $k = $v[$subKey];
                if ($incrementKey) {
                    $v[$incrementKey] = ($res[$incrementKey] ?? 0) + $incrementValue;
                }
                $tmp = array_merge($res[$k] ?? $fillable, $v);
                $new[$k] = json_encode($tmp);
                $res[$k] = $tmp;
            }

            if ($new && count($new) > 0) {
                $redis->hmSet($key, $new);
                if ($isNull) {
                    $redis->hdel($key, self::NIL_KEY);
                }
                $redis->expire($key, $ttl);
            }
        } else {
            if ($incrementKey) {
                $value[$incrementKey] = ($res[$incrementKey] ?? 0) + $incrementValue;
            }
            $res = array_merge($res ?? $fillable, $value);
            $redis->set($key, json_encode($res), $ttl);
        }

        return $res;
    }

    private function delRedisData($key, $subKeys, $isList, $group)
    {
        $redis = $this->redis->get($group);
        if ($isList) {
            $subKeys = is_array($subKeys) ? $subKeys : [$subKeys];
            $redis->hdel($key, ...$subKeys);
        } else {
            $redis->del($key);
        }
    }

    private function getTTl($ttl): int
    {
        return $ttl + random_int(100, 9999);
    }

    /* Redis */

    /* CONTEXT */
    /**
     * 获取上下文.
     *
     * @param mixed $key
     * @return array|mixed
     */
    private function getContext($key)
    {
        $res = Context::get($key);
        return $res ?? [];
    }

    /**
     * 设置上下文.
     * @param mixed $key
     * @param mixed $value
     */
    private function setContext($key, $value)
    {
        //        var_dump('setContext', $key, $value);
        Context::set($key, $value);
    }

    /**
     * 删除上下文.
     * @param mixed $key
     */
    private function delContext($key)
    {
        Context::destroy($key);
    }

    /* CONTEXT */

    private function isTwoDimensionalArrayWithFilter($array): bool
    {
        if (! is_array($array) || empty($array)) {
            return false;
        }

        $nonArrayItems = array_filter($array, function ($item) {
            return ! is_array($item);
        });

        return empty($nonArrayItems);
    }
}
