<?php

declare(strict_types=1);
/**
 * This file is part of cryjkd.
 *
 * @github   https://github.com/cryjkd
 */

namespace Cryjkd\ModelCache\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * @Annotation
 * @Target({"METHOD"})
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ModelPutCache extends AbstractAnnotation
{
    /**
     * @var string
     */
    public $prefix;

    /**
     * @var string
     */
    public $value;
}
