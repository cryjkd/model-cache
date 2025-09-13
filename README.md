## ModelCache

```
一个简单的hyperf框架的模型缓存.
二级缓存, redis与上下文
```

## 安装

```
composer require cryjkd/model-cache
```

## 配置

```
php bin/hyperf.php vendor:publish cryjkd/model-cache
```

```
return [
    'redis_select' => 'default',		//redis的名称
    'null_ttl' => 3600				    //数据库数据为空时,redis的过期时间
];
```

## 模型

更换模型的继承类, 更换成 `Cryjkd\ModelCache\BaseModel`

```
<?php

declare (strict_types=1);

namespace App\Model;

use Cryjkd\ModelCache\BaseModel;

class UserModel extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'userId';
    public $pkColumn = 'userId';
    protected $isList = false;
    protected $fillable = [
        'userId' => 0,
        'level' => 0,
        'name' => 0,
        'exp' => 0
    ];
}

```

### 属性

```
    /**
     * 主键
     *
     * @var string
     */
    public $pkColumn = 'id';

    /**
     * 主键-第二级
     *
     * @var string
     */
    public $subPkColumn = '';

    /**
     * 是否为列表
     *
     * @var bool
     */
    protected $isList = false;

    /**
     * 过期时间
     *
     * @var int
     */
    protected $ttl = 129600;

    /**
     * 是否开启上下文缓存
     *
     * @var bool
     */
    protected $useContext = true;
```

## 使用

- 获取数据

  ```
  $res = \Hyperf\Utils\ApplicationContext::getContainer()->get(UserModel::class)->getData($userId);
  ```

  `当`isList为false时,返回一维数组

  `当isList为true时,并且没有subPkColumn, 则为以pkColumn为key的二维数据`

  `当isList为true时,并且有subPkColumn, 则为以subPkColumn为key的二维数据`

- 更新数据

  ```
  \Hyperf\Utils\ApplicationContext::getContainer()->get(UserModel::class)->setData($userId, ['level' => 10]);
  ```

- 新增数据

  ```
  \Hyperf\Utils\ApplicationContext::getContainer()->get(UserModel::class)->addData($userId, ['userId' => $userId, 'level' => 10]);
  ```

- 删除数据

  ```
  \Hyperf\Utils\ApplicationContext::getContainer()->get(UserModel::class)->delData($userId);
  ```
