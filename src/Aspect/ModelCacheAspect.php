<?php

declare(strict_types=1);
/**
 * This file is part of cryjkd.
 *
 * @github   https://github.com/cryjkd
 */

namespace Cryjkd\ModelCache\Aspect;

use Cryjkd\ModelCache\Annotation\ModelCache;
use Cryjkd\ModelCache\ModelCacheService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * @Aspect
 */
class ModelCacheAspect extends AbstractAspect
{
    public array $classes = [];

    public array $annotations = [
        ModelCache::class,
    ];

    /**
     * @var ModelCacheService
     */
    protected $modelCacheService;

    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(ModelCacheService $modelCacheService, ConfigInterface $config)
    {
        $this->modelCacheService = $modelCacheService;
        $this->config = $config;
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $className = $proceedingJoinPoint->className;
        $method = $proceedingJoinPoint->methodName;
        $arguments = $proceedingJoinPoint->arguments['keys'];

        $primary = $arguments['primary'] ?? '';
        $pkColumn = $arguments['pkColumn'] ?? '';
        $subPkColumn = $arguments['subPkColumn'] ?? '';
        $ttl = $arguments['ttl'] ?? 0;
        $isList = $arguments['isList'] ?? false;
        $useContext = $arguments['useContext'] ?? true;
        $key = $this->modelCacheService->getCacheableValue(ModelCache::class, $className, $method, $arguments);

        $config = $this->config->get('model_cache');
        $group = $config['redis_select'] ?? 'default';
        $nullTtl = $config['null_ttl'] ?? 3600;

        if ($ttl !== 0) {
            $result = $this->modelCacheService->getCache($key, $isList, $group, $useContext);
            if ($result) {
                return $result == ModelCacheService::NIL_VALUE ? [] : $result;
            }
        }

        $result = $proceedingJoinPoint->process();
        if ($ttl !== 0) {
            $subKey = $subPkColumn ?: $pkColumn;
            if ($isList) {
                $result = $result ? array_column($result, null, $subKey) : [];
            }
            if (! $result) {
                $ttl = $nullTtl;
            }
            $this->modelCacheService->updateCache($key, $result ?: ModelCacheService::NIL_VALUE, $subKey, $isList, $group, $ttl, $useContext);
        }

        return $result;
    }
}
