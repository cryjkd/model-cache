<?php

declare(strict_types=1);
/**
 * This file is part of cryjkd.
 *
 * @github   https://github.com/cryjkd
 */

namespace Cryjkd\ModelCache;

use Cryjkd\ModelCache\Annotation\ModelCache;
use Cryjkd\ModelCache\Annotation\ModelEvictCache;
use Cryjkd\ModelCache\Annotation\ModelPutCache;
use Hyperf\DbConnection\Db;
use Hyperf\DbConnection\Model\Model;

/**
 * 数据库模型 - 基础类.
 */
class BaseModel extends Model
{
    /**
     * 主键.
     *
     * @var string
     */
    public $pkColumn = 'id';

    /**
     * 主键-第二级.
     *
     * @var string
     */
    public $subPkColumn = '';

    /**
     * 关闭自动维护时间字段.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 是否为列表.
     *
     * @var bool
     */
    protected $isList = false;

    /**
     * 过期时间.
     *
     * @var int
     */
    protected $ttl = 129600;

    /**
     * 是否开启上下文缓存.
     *
     * @var bool
     */
    protected $useContext = true;

    public function getIsList(): bool
    {
        return $this->isList;
    }

    public function getFillableKey(): array
    {
        return array_keys($this->fillable);
    }

    /* VO CACHE */

    /**
     * 获取模型数据.
     *
     * @param mixed $primary
     * @param null|mixed $keys
     * @return array|mixed|string
     */
    public function getData($primary, $keys = null)
    {
        if ($this->isList) {
            if ($this->subPkColumn) {
                if (! $keys) {
                    return [];
                }
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        $res[$key] = $this->getVoListCache($this->table, $primary, $this->pkColumn, $this->subPkColumn, $this->ttl, $key);
                    }
                } else {
                    $res = $this->getVoListCache($this->table, $primary, $this->pkColumn, $this->subPkColumn, $this->ttl, $keys);
                }
            } else {
                $res = $this->getVoListCache($this->table, $primary, $this->pkColumn, $this->subPkColumn, $this->ttl);
            }
            $keys = null;
        } else {
            $res = $this->getVoCache($this->table, $primary, $this->ttl, $this->useContext);
        }

        if ($keys) {
            if (is_array($keys)) {
                $list = [];
                foreach ($keys as $key) {
                    $list[$key] = $res[$key] ?? '';
                }
            } else {
                $list = $res[$keys] ?? '';
            }

            return $list ?? '';
        }

        return $res ?? '';
    }

    /**
     * 更新模型数据.
     *
     * @param mixed $primary
     * @return null|int
     */
    public function setData($primary, array $data, array $increment = [])
    {
        if ($this->isList) {
            $subKey = '';
            if ($this->subPkColumn) {
                $subKey = $data[$this->pkColumn] ?? '';
                $subKey = $subKey ?? $data[array_key_first($data)][$this->pkColumn] ?? '';
            }
            return $this->setVoListCache($this->table, $primary, $data, $this->ttl, $this->pkColumn, $this->subPkColumn, $this->fillable, $increment, $subKey);
        }
        return $this->setVoCache($this->table, $primary, $data, $this->ttl, $this->fillable, $increment);
    }

    /**
     * 新增模型数据.
     *
     * @param mixed $primary
     * @return int
     */
    public function addData($primary, array $data)
    {
        if ($this->isList) {
            $subKey = '';
            if ($this->subPkColumn) {
                $subKey = $data[$this->pkColumn] ?? '';
                $subKey = $subKey ?? $data[array_key_first($data)][$this->pkColumn] ?? '';
            }
            return $this->addVoListCache($this->table, $primary, $data, $this->ttl, $this->pkColumn, $this->subPkColumn, $this->fillable, $subKey);
        }
        return $this->addVoCache($this->table, $primary, $data, $this->ttl, $this->fillable);
    }

    /**
     * 删除模型数据.
     *
     * @param mixed $primary
     * @param mixed $pkColumnValue
     * @param mixed $subPkColumnValue
     * @return mixed
     */
    public function delData($primary, $pkColumnValue = '', $subPkColumnValue = '')
    {
        if ($this->isList) {
            if (! isset($pkColumnValue)) {
                throw new \RuntimeException(sprintf('List model missing pkColumnValue.'));
            }
            $subKey = $subPkColumnValue ? $pkColumnValue : '';
            return $this->delVoListCache($this->table, $primary, $pkColumnValue, $subPkColumnValue, $subKey, $this->ttl);
        }
        return $this->delVoCache($this->table, $primary, $this->ttl);
    }

    /**
     * 批量更新.
     *
     * @param mixed $primaryValue
     * @param mixed $increment
     * @param mixed $data
     * @return int
     */
    public function batchUpdate($primaryValue, $data, $increment = [])
    {
        $final = [];
        $ids = [];
        if (! count($data)) {
            return 0;
        }
        $primaryKey = $this->primaryKey;
        $pkColumn = $this->pkColumn;
        $subPkColumn = $this->subPkColumn;

        $incrementKey = $increment[0] ?? ''; // 获取需要自增的字段名
        $incrementValue = $increment[1] ?? 0; // 获取自增的值

        $subStr = '';
        foreach ($data as $val) {
            if ($subPkColumn) {
                $pIdKey = $subPkColumn;
                $ids[] = $val[$subPkColumn];
                $subStr = ' and `' . $pkColumn . '` = ' . $val[$pkColumn];
            } else {
                $pIdKey = $pkColumn;
                $ids[] = $val[$pkColumn];
            }

            $fields = array_keys($val);
            foreach ($fields as $field) {
                if ($field !== $pIdKey) {
                    if ($incrementKey && $field == $incrementKey) { // 如果是需要自增的字段
                        $tValue = "`{$field}` + {$incrementValue}"; // 使用自增形式
                    } else {
                        $tValue = (is_null($val[$field]) ? 'NULL' : "'" . $val[$field] . "'");
                    }
                    $final[$field][] = 'WHEN `' . $pIdKey . "` = '" . $val[$pIdKey] . "' THEN " . $tValue . ' ';
                }
            }
        }
        $cases = '';
        foreach ($final as $k => $v) {
            $cases .= '`' . $k . '` = (CASE ' . implode(' ', $v) . ' '
                . 'ELSE `' . $k . '` END), ';
        }

        $query = 'UPDATE ' . env('DB_PREFIX') . $this->table . '` SET ' . substr($cases, 0, -2) . ' WHERE `' . $primaryKey . '` = ' . $primaryValue . $subStr . ' and `' . $pIdKey . '` IN(' . "'" . implode("','", $ids) . "'" . ');';

        return Db::update($query);
    }

    /* DB */

    /**
     * @ModelCache(prefix="Vo", value="#{table}:#{primary}")
     * @param mixed $primary
     */
    private function getVoCache(string $table, $primary, int $ttl = -1, bool $useContext = true, bool $isList = false): array
    {
        $res = $this->where($this->primaryKey, $primary)->first();
        return $res ? $res->toArray() : [];
    }

    /**
     * @ModelCache(prefix="VoList", value="#{table}:#{primary}:#{subKey}")
     * @param mixed $primary
     * @param mixed $pkColumn
     * @param mixed $subPkColumn
     * @param mixed $subKey
     */
    private function getVoListCache(string $table, $primary, $pkColumn = '', $subPkColumn = '', int $ttl = 0, $subKey = '', bool $isList = true): array
    {
        $model = $this->where($this->primaryKey, $primary);
        if ($subKey) {
            $model->where($this->pkColumn, $subKey);
        }
        $res = $model->get();
        return $res ? $res->toArray() : [];
    }

    /**
     * @ModelPutCache(prefix="Vo", value="#{table}:#{primary}")
     * @param mixed $table
     * @param mixed $primary
     * @param mixed $increment
     */
    private function setVoCache($table, $primary, array $data, int $ttl = 0, array $fillable = [], $increment = [], bool $isList = false)
    {
        $model = $this->where($this->primaryKey, $primary);
        if ($increment) {
            $k = $increment[0] ?? '';
            $v = $increment[1] ?? '';

            if ($k) {
                unset($data[$k]);
                $res = $model->increment($k, $v, $data);
            }
        } else {
            $res = $model->update($data);
        }

        return $res ?? null;
    }

    /**
     * @ModelPutCache(prefix="VoList", value="#{table}:#{primary}:#{subKey}")
     * @param mixed $table
     * @param mixed $primary
     * @param mixed $pkColumn
     * @param mixed $subPkColumn
     * @param mixed $increment
     * @param mixed $subKey
     */
    private function setVoListCache($table, $primary, array $data, int $ttl = 0, $pkColumn = '', $subPkColumn = '', array $fillable = [], $increment = [], $subKey = '', bool $isList = true)
    {
        if (isset($data[$pkColumn]) && $data[$pkColumn]) {
            $model = $this->where($this->primaryKey, $primary)->where($pkColumn, $data[$pkColumn]);
            if (isset($data[$subPkColumn]) && $data[$subPkColumn]) {
                $model->where($subPkColumn, $data[$subPkColumn]);
            }

            if ($increment) {
                $k = $increment[0] ?? '';
                $v = $increment[1] ?? '';
                if ($k) {
                    unset($data[$k]);
                    $res = $model->increment($k, $v, $data);
                }
            } else {
                $res = $model->update($data);
            }
        } else {
            $res = $this->batchUpdate($primary, $data, $increment);
        }

        return $res ?? null;
    }

    /**
     * @ModelPutCache(prefix="Vo", value="#{table}:#{primary}")
     * @param mixed $table
     * @param mixed $primary
     */
    private function addVoCache($table, $primary, array $data, int $ttl = 0, array $fillable = [], bool $isList = false): bool
    {
        return $this->insert($data);
    }

    /**
     * @ModelPutCache(prefix="VoList", value="#{table}:#{primary}:#{subKey}")
     * @param mixed $table
     * @param mixed $primary
     * @param mixed $pkColumn
     * @param mixed $subPkColumn
     * @param mixed $subKey
     */
    private function addVoListCache($table, $primary, array $data, int $ttl = 0, $pkColumn = '', $subPkColumn = '', array $fillable = [], $subKey = '', bool $isList = true): bool
    {
        return $this->insert($data);
    }

    /**
     * @ModelEvictCache(prefix="Vo", value="#{table}:#{primary}")
     * @param mixed $table
     * @param mixed $primary
     */
    private function delVoCache($table, $primary, int $ttl = 0, bool $isList = false)
    {
        return $this->where($this->primaryKey, $primary)->delete();
    }

    /**
     * @ModelEvictCache(prefix="VoList", value="#{table}:#{primary}:#{subKey}")
     * @param mixed $table
     * @param mixed $primary
     * @param mixed $pkColumnValue
     * @param mixed $subPkColumnValue
     * @param mixed $subKey
     */
    private function delVoListCache($table, $primary, $pkColumnValue, $subPkColumnValue = '', $subKey = '', int $ttl = 0, bool $isList = true)
    {
        $model = $this->where($this->primaryKey, $primary);
        if ($this->subPkColumn) {
            $subPkColumnValue = is_array($subPkColumnValue) ? $subPkColumnValue : [$subPkColumnValue];
            $model->where($this->pkColumn, $pkColumnValue);
            $res = $model->whereIn($this->subPkColumn, $subPkColumnValue)->delete();
        } else {
            $pkColumnValue = is_array($pkColumnValue) ? $pkColumnValue : [$pkColumnValue];
            $res = $model->whereIn($this->pkColumn, $pkColumnValue)->delete();
        }

        return $res;
    }

    /* DB */
}
