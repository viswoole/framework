<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Database\Channel\PDO;

use PDO;
use Viswoole\Core\Common\Arr;
use Viswoole\Database\Collector\CrudMethod;
use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\Collector\Raw;
use Viswoole\Database\Collector\Where\WhereGroup;
use Viswoole\Database\Collector\Where\WhereRaw;
use Viswoole\Database\Exception\DbException;

/**
 * sql构造器
 */
class SqlBuilder
{
  const array TAG = [
    PDODriverType::MYSQL->name => [
      'left' => '`',
      'right' => '`'
    ],
    PDODriverType::ORACLE->name => [
      'left' => '"',
      'right' => '"'
    ],
    PDODriverType::POSTGRESQL->name => [
      'left' => '"',
      'right' => '"'
    ],
    PDODriverType::SQLite->name => [
      'left' => '[',
      'right' => ']'
    ]
  ];
  /**
   * @var array 缓存表字段列表
   */
  private static array $tableFields = [];
  protected array $params = [];

  /**
   * @param PDOChannel $driver
   * @param QueryOptions $options
   */
  public function __construct(protected PDOChannel $driver, protected QueryOptions $options)
  {

  }

  /**
   * 构建sql
   *
   * @param bool $merge
   * @return string|array
   */
  public function build(bool $merge): string|array
  {
    $sql = [];
    $sql[] = $this->buildBaseSql();
    $sql[] = $this->buildWhere();
    if (
      $this->options->crudMethod === CrudMethod::SELECT
      || $this->options->crudMethod === CrudMethod::FIND
    ) {
      $sql[] = $this->buildJoin();
      $sql[] = $this->buildGroup();
      $sql[] = $this->buildHaving();
      $sql[] = $this->buildOrder();
      $sql[] = $this->buildLimit();
      $sql[] = $this->buildUnion();
    }
    $sql = array_filter($sql, function ($item) {
      return !empty($item);
    });
    $sqlStr = implode(' ', $sql);
    if ($merge) return self::sqlMergeParams($sqlStr, $this->params);;
    return [
      'sql' => $sqlStr,
      'params' => $this->params
    ];
  }

  /**
   * 生成基本sql语句
   *
   * @return string
   */
  private function buildBaseSql(): string
  {
    $table = $this->quote($this->options->table);
    $operaType = $this->options->crudMethod;
    if ($this->options->alias) {
      $table = $table . ' AS ' . $this->quote($this->options->alias);
    }
    $DISTINCT = $this->options->distinct ? ' DISTINCT' : '';
    $PARTITION = '';
    if ($this->driver->type === PDODriverType::MYSQL && !empty($this->options->partition)) {
      $partition = implode(', ', $this->options->partition);
      $PARTITION = " PARTITION ($partition)";
    }
    $USE_INDEX = '';
    if (!empty($this->options->force)) {
      $force = $this->options->force;
      foreach ($force as $index) {
        $USE_INDEX .= " USE INDEX ($index)";
      }
    }
    if (in_array($operaType->name, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'])) {
      $columnName = $this->options->columnName;
      if ($columnName !== '*') $columnName = $this->quote($columnName);
      $countName = $this->options->columnName . '_' . strtolower($operaType->name);
      return /** @lang text */ "SELECT COUNT($columnName) AS $countName FROM $table$PARTITION$USE_INDEX";
    }
    if ($operaType === CrudMethod::INSERT) {
      // 写入方式
      $replace = 'INSERT';
      if ($this->driver->type === PDODriverType::MYSQL) {
        $replace = $this->options->replace ? 'REPLACE' : 'INSERT';
      }
      return "$replace INTO $table " . $this->parseInsertData();
    }
    if ($operaType === CrudMethod::SELECT || $operaType === CrudMethod::FIND) {
      $field = $this->parseFields();
      return /** @lang text */ "SELECT$DISTINCT $field FROM $table$PARTITION$USE_INDEX";
    }
    if ($operaType === CrudMethod::DELETE) {
      return /** @lang text */ "DELETE FROM $table";
    }
    return /** @lang text */ "UPDATE $table SET " . $this->parseUpdateData();
  }

  /**
   * 用标识符包裹字段
   *
   * @param string $str
   * @return string
   */
  private function quote(string $str): string
  {
    if (
      str_contains($str, '.')
      || str_contains($str, '`')
      || str_contains($str, '"')
      || str_contains($str, '[')
    ) return $str;
    return self::TAG[$this->driver->type->name]['left'] . $str . self::TAG[$this->driver->type->name]['right'];
  }

  /**
   * 解析写入数据
   *
   * @return string
   */
  private function parseInsertData(): string
  {
    $data = $this->options->data;
    if (Arr::isAssociativeArray($data)) {
      $keys = array_map(function ($item) {
        return $this->quote($item);
      }, array_keys($data));
      $values = array_values($data);
      $keys = '(' . implode(', ', $keys) . ')';
      $param_keys = [];
      foreach ($values as $value) {
        $param_key = 'PARAM_' . count($this->params);
        $this->params[$param_key] = $value;
        $param_keys[] = ':' . $param_key;
      }
      $values = '(' . implode(', ', $param_keys) . ')';
      $queryBody = $keys . ' VALUES ' . $values;
    } else {
      $valuesItems = [];
      $keys = '';
      foreach ($data as $insertItem) {
        $keys = array_map(function ($item) {
          return $this->quote($item);
        }, array_keys($insertItem));
        $keys = ' (' . implode(', ', $keys) . ')';
        $values = array_values($insertItem);
        $param_keys = [];
        foreach ($values as $value) {
          $param_key = 'PARAM_' . count($this->params);
          $this->params[$param_key] = $value;
          $param_keys[] = ':' . $param_key;
        }
        $valuesItems[] = '(' . implode(', ', $param_keys) . ')';
      }
      $queryBody = $keys . ' VALUES ' . implode(', ', $valuesItems);
    }
    return $queryBody;
  }

  /**
   * 解析要查询的字段
   *
   * @return string
   */
  private function parseFields(): string
  {
    $withoutField = $this->options->withoutField;
    $fields = $this->options->field;
    if ($fields === '*' && empty($withoutField)) return '*';
    if ($fields instanceof Raw) return $fields->sql;
    if (!empty($withoutField)) {
      $fullFields = $this->getTableFields();
      foreach ($fullFields as $kye => $value) {
        // 如果当前表字段存在排除字段中中 则移除该字段
        if (in_array($value, $withoutField)) {
          unset($fullFields[$kye]);
        } elseif (isset($fields[$value])) {
          // 判断字段是否通过field方法设置了别名 设置了则需要添加别名
          $keys = array_keys($fullFields);
          $keys[$kye] = $value;
          $fullFields[$kye] = $fields[$value];
          $newFullFields = array_combine($keys, $fullFields);
          if ($newFullFields) $fullFields = $newFullFields;
        }
      }
      $fields = $fullFields;
    } else {
      if ($fields === '*') return '*';
    }
    $parsedFields = [];
    foreach ($fields as $key => $value) {
      if (is_int($key)) {
        $parsedFields[] = $value;
      } else {
        $parsedFields[] = $key . ' AS ' . $value;
      }
    }
    $fields = implode(', ', $parsedFields);
    return rtrim($fields, ', ');
  }

  /**
   * 获取表字段列表
   *
   * @return array
   */
  private function getTableFields(): array
  {
    $table = $this->quote($this->options->table);
    if (isset(self::$tableFields[$table])) return self::$tableFields[$table];
    $sql = match ($this->driver->type->value) {
      'sqlite' => "PRAGMA table_info([$table])",
      'pgsql' => /** @lang text */
      "SELECT column_name FROM information_schema.columns WHERE table_name = '$table'",
      default => "DESCRIBE `$table`"
    };
    $conn = $this->driver->pop('read');
    $statement = $conn->query($sql);
    $this->driver->put($conn);
    if (!$statement) throw new DbException('获取数据表结构失败', 10500, $sql);
    $fields = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$fields) throw new DbException('获取数据表结构失败', 10500, $sql);
    self::$tableFields[$table] = $fields;
    return $fields;
  }

  /**
   * 解析更新数据
   *
   * @return string
   */
  private function parseUpdateData(): string
  {
    $data = $this->options->data;
    $sql = [];
    foreach ($data as $key => $value) {
      $key = $this->quote($key);
      if ($value instanceof Raw) {
        $sql[] = "$key = $value->sql";
      } else {
        $PARAM_key = 'PARAM_' . count($this->params);
        $sql[] = "$key = :$PARAM_key";
        $this->params[$PARAM_key] = $value;
      }
    }
    if (empty($this->options->where)) {
      if (isset($data[$this->options->pk])) {
        $this->options->addWhere(
          $this->options->pk,
          '=',
          $data[$this->options->pk],
          'AND'
        );
      }
    }
    return implode(', ', $sql);
  }

  /**
   * 构建where条件
   *
   * @return string
   */
  private function buildWhere(): string
  {
    $wheres = $this->options->where;
    $parsedWheres = [];
    foreach ($wheres as $where) {
      if ($where instanceof WhereRaw) {
        $parsedWheres[] = $where->sql;
        if (!empty($where->params)) {
          $this->params = array_merge($this->params, $where->params);
        }
      } elseif ($where instanceof WhereGroup) {
        $group = [];
        foreach ($where->items as $item) {
          $group[] = $this->parseWhereItem($item);
        }
        $groupString = implode(' ', $group);
        $groupString = preg_replace('/^(AND |OR )/', '', $groupString);
        $groupString = "$where->connector ($groupString)";
        $parsedWheres[] = $groupString;
      } else {
        $parsedWheres[] = $this->parseWhereItem($where);
      }
    }
    if (empty($parsedWheres)) return '';
    $whereString = implode(' ', $parsedWheres);
    $whereString = preg_replace('/^(AND |OR )/', '', $whereString);
    return 'WHERE ' . $whereString;
  }

  /**
   * 解析查询条件
   *
   * @param array{column:string,operator:string,value:mixed,connector:string} $where
   * @return string
   */
  private function parseWhereItem(array $where): string
  {
    $column = $this->quote($where['column']);
    $operator = $where['operator'];
    $value = $where['value'];
    $connector = $where['connector'];
    if ($value === null) {
      if ($operator === '=') {
        $where = "$connector $column IS NULL";
      } else {
        $where = "$connector $column IS NOT NULL";
      }
      return $where;
    }
    if (is_array($value)) {
      foreach ($value as &$item) {
        $PARAM_KEY = 'PARAM_' . count($this->params);
        $this->params[$PARAM_KEY] = $item;
        $item = ':' . $PARAM_KEY;
      }
      $where = "$connector $column $operator (" . implode(', ', $value) . ')';
    } else {
      $PARAM_KEY = 'PARAM_' . count($this->params);
      $this->params[$PARAM_KEY] = $value;
      $where = "$connector $column $operator :$PARAM_KEY";
    }
    return $where;
  }

  /**
   * 关联查询
   *
   * @return string
   */
  private function buildJoin(): string
  {
    $join = [];
    if (!empty($this->options->join)) {
      foreach ($this->options->join as $joinItem) {
        $type = $joinItem['type'];
        $table = $joinItem['table'];
        $condition = $joinItem['condition'];
        $join[] = "$type JOIN $table ON $condition";
      }
    }
    return implode(' ', $join);
  }

  /**
   * 分组
   *
   * @return string
   */
  private function buildGroup(): string
  {
    if (!empty($this->options->group)) {
      $group = array_map(function ($item) {
        return $this->quote($item);
      }, $this->options->group);
      return ' GROUP BY ' . implode(', ', $group);
    }
    return '';
  }

  /**
   * having
   *
   * @return string
   */
  private function buildHaving(): string
  {
    if (!empty($this->options->having)) {
      $having = $this->options->having;
      return " HAVING $having";
    }
    return '';
  }

  /**
   * 排序
   *
   * @return string
   */
  private function buildOrder(): string
  {
    if (!empty($this->options->order)) {
      $order = [];
      foreach ($this->options->order as $col => $direction) {
        $order[] = $this->quote($col) . ' ' . $direction;
      }
      return ' ORDER BY ' . implode(', ', $order);
    }
    return '';
  }

  /**
   * @return string
   */
  private function buildLimit(): string
  {
    if ($this->options->limit) {
      return 'LIMIT ' . $this->options->limit;
    }
    return '';
  }

  /**
   * @return string
   */
  private function buildUnion(): string
  {
    $unionAll = [];
    if (!empty($this->options->unionAll)) {
      $unionAll = array_map(fn($item) => "UNION ALL $item", $this->options->unionAll);
    }
    $union = [];
    if (!empty($this->options->union)) {
      $union = array_map(fn($item) => "UNION $item", $this->options->union);
    }
    return implode(' ', array_merge($unionAll, $union));
  }

  /**
   * 合并参数
   *
   * @param string $sql
   * @param array $params
   * @return string
   */
  public static function sqlMergeParams(string $sql, array $params): string
  {
    if (!empty($params)) {
      // 替换参数值
      $patterns = array_map(function ($param) {
        return '/(?<!\w):' . preg_quote($param, '/') . '(?!\w)/';
      }, array_keys($params));

      $sql = preg_replace_callback($patterns, function ($matches) use ($params) {
        $paramKey = ltrim($matches[0], ':');
        $paramValue = $params[$paramKey];
        if ($paramValue === null) return 'NULL';
        return is_string($paramValue) ? "'" . addslashes($paramValue) . "'" : $paramValue;
      }, $sql);
    }
    return $sql;
  }
}
