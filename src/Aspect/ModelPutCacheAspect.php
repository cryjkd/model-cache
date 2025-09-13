<?php

declare(strict_types=1);
/**
 * This file is part of cryjkd.
 *
 * @github   https://github.com/cryjkd
 */

namespace Cryjkd\ModelCache\Aspect;

use Cryjkd\ModelCache\Annotation\ModelPutCache;
use Cryjkd\ModelCache\ModelCacheService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

/**
 * @Aspect
 */
#[Aspect]
class ModelPutCacheAspect extends AbstractAspect
{
    public array $classes = [];

    public array $annotations = [
        ModelPutCache::class,
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
        try {
            if ($proceedingJoinPoint->process()) {
                $this->updateCache($proceedingJoinPoint);
            }
        } catch (\Exception $exception) {
            $className = $proceedingJoinPoint->className;
            $method = $proceedingJoinPoint->methodName;
            $arguments = $proceedingJoinPoint->arguments['keys'];

            $key = $this->modelCacheService->getCacheableValue(ModelPutCache::class, $className, $method, $arguments);
            $group = $this->config->get('model_cache.redis_select', 'default');
            $this->modelCacheService->destroyCache($key, $group);

            var_dump($exception);
        }

        return true;
    }

    public function updateCache($proceedingJoinPoint): bool
    {
        $className = $proceedingJoinPoint->className;
        $method = $proceedingJoinPoint->methodName;
        $arguments = $proceedingJoinPoint->arguments['keys'];

        $primary = $arguments['primary'] ?? '';
        $data = $arguments['data'];
        $increment = $arguments['increment'] ?? [];
        $pkColumn = $arguments['pkColumn'] ?? '';
        $subPkColumn = $arguments['subPkColumn'] ?? '';
        $ttl = $arguments['ttl'] ?? 0;
        $isList = $arguments['isList'] ?? false;
        $useContext = $arguments['useContext'] ?? true;
        $fillable = $arguments['fillable'] ?? [];
        if ($ttl == 0) {
            return true;
        }

        $key = $this->modelCacheService->getCacheableValue(ModelPutCache::class, $className, $method, $arguments);
        if (isset($data[$pkColumn])) {
            $data = [$data];
        }

        $subKey = $subPkColumn ?: $pkColumn;
        $group = $this->config->get('model_cache.redis_select', 'default');
        $this->modelCacheService->setCache($key, $data, $increment, $subKey, $isList, $ttl, $fillable, $group, $useContext);

        return true;
    }
}
