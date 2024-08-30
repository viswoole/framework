<?php /** @noinspection SqlDialectInspection */
/** @noinspection SqlNoDataSourceInspection */
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
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Query\WhereGroup;
use Viswoole\Database\Raw;

/**
 * sql构造器
 */
class SqlBuilder
{
  const array TAG = [
    DriverType::MYSQL->name => [
      'left' => '`',
      'right' => '`'
    ],
    DriverType::ORACLE->name => [
      'left' => '"',
      'right' => '"'
    ],
    DriverType::POSTGRESQL->name => [
      'left' => '"',
      'right' => '"'
    ],
    DriverType::SQLServer->name => [
      'left' => '[',
      'right' => ']'
    ],
    DriverType::SQLite->name => [
      'left' => '`',
      'right' => '`'
    ]
  ];
  /**
   * @var array 缓存表字段列表
   */
  private static array $tableColumns = [];
  /**
   * @var array sql参数列表
   */
  protected array $params = [];

  /**
   * @param PDOChannel $channel 数据库类型
   * @param Options $options
   */
  public function __construct(protected PDOChannel $channel, protected Options $options)
  {
  }

  /**
   * 生成基本sql语句
   *
   * @return Raw
   */
  public function build(): Raw
  {
    $sql = match ($this->options->type) {
      'insert', 'insertGetId' => $this->buildInsert(),
      'update' => $this->buildUpdate(),
      'delete' => $this->buildDelete(),
      'select' => $this->buildSelect(),
      default => throw new DbException('不支持的查询类型'),
    };
    // 锁
    $sql .= $this->parseLockForUpdate();
    $sql .= $this->parseSharedLock();
    // 返回Raw对象
    return Db::raw($sql, $this->params);
  }

  /**
   * 写入数据
   *
   * @return string
   */
  public function buildInsert(): string
  {
    // 表名
    $table = $this->quote($this->options->table);
    // 插入语句
    $sql = ($this->options->replace && $this->channel->type === DriverType::MYSQL)
      ? 'REPLACE INTO '
      : 'INSERT INTO ';
    // 获得写入的字段
    $keys = Arr::isIndexArray($this->options->data)
      ? array_keys(reset($this->options->data))
      : array_keys($this->options->data);

    // 要写入的列
    $quotedKeys = array_map([$this, 'quote'], $keys);
    $columns = implode(', ', $quotedKeys);
    // 如果是索引数组，说明是批量写入
    if (Arr::isIndexArray($this->options->data)) {
      $rows = [];
      foreach ($this->options->data as $record) {
        if (array_keys($record) !== $keys) {
          throw new DbException('批量写入数据时，所有写入数据的字段必须一致');
        }
        $values = $this->parseDataToValues($record);
        $rows[] = '(' . implode(', ', $values) . ')';
      }
      $sql .= "$table ($columns) VALUES " . implode(', ', $rows);
    } else {
      // 单条记录写入
      $values = $this->parseDataToValues($this->options->data);
      $values = implode(', ', $values);
      $sql .= "$table ($columns) VALUES ($values)";
    }
    return $sql;
  }

  /**
   * 用标识符包裹字段
   *
   * @param string $str
   * @return string
   */
  protected function quote(string $str): string
  {
    $str = trim($str);
    if (preg_match('/[.`"[\]()]| /', $str)) {
      return $str;
    }
    $type = $this->channel->type->name;
    return self::TAG[$type]['left'] . $str . self::TAG[$type]['right'];
  }

  /**
   * 解析数据为values
   *
   * @param $row
   * @return array
   */
  private function parseDataToValues($row): array
  {
    $values = [];
    foreach ($row as $value) {
      if ($value instanceof Raw) {
        $this->params = array_merge($this->params, $value->bindings);
        $values[] = $value->sql;
      } else {
        $this->params[] = $value;
        $values[] = '?';
      }
    }
    return $values;
  }

  /**
   * 打包更新语句
   *
   * @return string
   */
  public function buildUpdate(): string
  {
    $table = $this->quote($this->options->table);
    $data = $this->parseUpdateData();
    $where = $this->parseWhere();
    return "UPDATE $table SET $data $where";
  }

  /**
   * 解析更新数据
   *
   * @return string
   */
  protected function parseUpdateData(): string
  {
    $data = $this->options->data;
    $sql = [];
    foreach ($data as $key => $value) {
      $column = $this->quote($key);
      if ($value instanceof Raw) {
        $this->params = array_merge($this->params, $value->bindings);
        $sql[] = "$column = $value->sql";
      } else {
        $this->params[] = $value;
        $sql[] = "$column = ?";
      }
    }
    if (empty($this->options->where)) {
      if (isset($data[$this->options->pk])) {
        $this->options->where[] = [
          'column' => $this->quote($this->options->pk),
          'operator' => '=',
          'value' => $data[$this->options->pk],
          'connector' => 'AND'
        ];
      } else {
        $pk = $this->quote($this->options->pk);
        throw new DbException("更新数据时，必须指定where条件,或在更新数据中包含{$pk}主键值");
      }
    }
    return implode(', ', $sql);
  }

  /**
   * 解析Where语句
   *
   * @return string
   */
  public function parseWhere(): string
  {
    if (empty($this->options->where)) return '';
    $wheres = $this->options->where;
    $parsedWheres = [];
    foreach ($wheres as $where) {
      $parsedWheres[] = $this->parseWhereItem($where);
    }
    $whereString = implode(' ', $parsedWheres);
    $whereString = preg_replace('/^(AND |OR )/', '', $whereString);
    return 'WHERE ' . $whereString;
  }

  /**
   * 解析查询条件
   *
   * @param array{column:string,operator:string,value:mixed,connector:string}|Raw|WhereGroup $where
   * @return string
   */
  protected function parseWhereItem(array|Raw|WhereGroup $where): string
  {
    if ($where instanceof Raw) {
      $this->params = array_merge($this->params, $where->bindings);
      return $where->sql;
    } elseif ($where instanceof WhereGroup) {
      return $this->parseWhereGroup($where);
    } else {
      $column = $this->quote($where['column']);
      $operator = $where['operator'];
      $value = $where['value'];
      $connector = $where['connector'];
      if ($value === null) {
        if (in_array($operator, ['IS NULL', 'IS NOT NULL'])) {
          $where = "$connector $column $operator";
        } elseif ($operator === '=') {
          $where = "$connector $column IS NULL";
        } elseif ($operator === '!=' || $operator === '<>') {
          $where = "$connector $column IS NOT NULL";
        } else {
          throw new DbException(
            '当查询条件值为null时，有效的运算符为=,!=,<>,IS NULL,IS NOT NULL',
            sql: "... $connector $column $operator null"
          );
        }
        return $where;
      }
      if (is_array($value)) {
        array_walk($value, function (&$item) {
          $this->params[] = $item;
          $item = '?';
        });
        $value = implode(', ', $value);
        $where = "$connector $column $operator ($value)";
      } else {
        $this->params[] = $value;
        $where = "$connector $column $operator ?";
      }
      return $where;
    }
  }

  /**
   * 解析WhereGroup
   *
   * @param WhereGroup $where
   * @return string
   */
  protected function parseWhereGroup(WhereGroup $where): string
  {
    $group = [];
    foreach ($where->items as $item) {
      $group[] = $this->parseWhereItem($item);
    }
    $groupString = implode(' ', $group);
    $groupString = preg_replace('/^(AND |OR )/', '', $groupString);
    return "$where->connector ($groupString)";
  }

  /**
   * 打包删除语句
   *
   * @return string
   */
  public function buildDelete(): string
  {
    $table = $this->quote($this->options->table);
    $where = $this->parseWhere();
    return "DELETE FROM $table $where";
  }

  /**
   * 构建Select语句
   *
   * @return string
   */
  public function buildSelect(): string
  {
    $table = $this->quote($this->options->table);
    if (!empty($this->options->alias)) {
      $table .= " AS {$this->quote($this->options->alias)}";
    }
    $columns = $this->parseColumns();
    $select = $this->options->distinct ? 'SELECT DISTINCT' : 'SELECT';
    $sql = [];
    // SELECT 和 FROM 子句
    $sql[] = "$select $columns FROM $table";
    // FORCE 子句
    $sql[] = $this->parseForce();
    // JOIN 子句
    $sql[] = $this->parseJoin();
    // WHERE 子句
    $sql[] = $this->parseWhere();
    // UNION 子句
    $sql[] = $this->parseUnion();
    // GROUP BY 子句
    $sql[] = $this->parseGroupBy();
    // HAVING 子句
    $sql[] = $this->parseHaving();
    // ORDER BY 子句
    $sql[] = $this->parseOrderBy();
    // LIMIT 子句
    $sql[] = $this->parseLimit();
    // 移除空字符串项
    $sql = array_filter($sql, function ($item) {
      return !empty($item);
    });

    // 拼接 SQL 语句
    return implode(' ', $sql);
  }

  /**
   * 解析选择的列
   *
   * @return string
   */
  protected function parseColumns(): string
  {
    $withoutField = $this->options->withoutColumns;
    $fields = $this->options->columns;
    if (empty($fields) && empty($withoutField)) return '*';
    // 如果有排除的字段 则获取全部字段来进行排除
    if (!empty($withoutField)) {
      $fullFields = array_filter($this->getTableColumns(), function ($item) use ($withoutField) {
        return !in_array($item, $withoutField);
      });
      // 将索引数组转换为关联数组
      $fullFields = array_combine($fullFields, array_fill(0, count($fullFields), null));
      array_walk($fullFields, function (&$item, $key) {
        if (isset($fields[$key])) $item = $fields[$key];
      });
      $fields = $fullFields;
    }
    $parsedFields = [];
    foreach ($fields as $key => $value) {
      if (is_int($key)) {
        $parsedFields[] = $this->quote($value);
        continue;
      }
      $key = $this->quote($key);
      if (empty($value)) {
        $parsedFields[] = $key;
      } else {
        $parsedFields[] = $key . ' AS ' . $this->quote($value);
      }
    }
    return implode(', ', $parsedFields);
  }

  /**
   * 获取表字段列表
   *
   * @return array
   */
  protected function getTableColumns(): array
  {
    $table = $this->options->table;
    if (isset(self::$tableColumns[$table])) return self::$tableColumns[$table];
    $sql = match ($this->channel->type->value) {
      'sqlite' => "PRAGMA table_info($table)",
      'pgsql' => "SELECT column_name FROM information_schema.columns WHERE table_name = '$table'",
      'oci' => "SELECT column_name FROM USER_TAB_COLUMNS WHERE table_name = '$table'",
      'sqlsrv' => "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$table'",
      default => "DESCRIBE `$table`"
    };
    $conn = $this->channel->pop('read');
    $statement = $conn->query($sql);
    $this->channel->put($conn);
    if (!$statement) throw new DbException('获取数据表结构失败', 500, $sql);
    if ($this->channel->type === DriverType::SQLite) {
      $fields = $statement->fetchAll(PDO::FETCH_ASSOC);
      $fields = array_column($fields, 'name');
    } else {
      $fields = $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!$fields) throw new DbException('获取数据表结构失败', 500, $sql);
    self::$tableColumns[$table] = $fields;
    return $fields;
  }

  /**
   * 强制索引
   *
   * @return string
   */
  protected function parseForce(): string
  {
    if (empty($this->options->force)) return '';
    $index = $this->quote($this->options->force);
    return match ($this->channel->type) {
      DriverType::MYSQL => "FORCE INDEX($index)",
      DriverType::SQLite => "a WITH (INDEX($index))",
      default => '',
    };
  }

  /**
   * 解析Join语句
   *
   * @return string
   */
  protected function parseJoin(): string
  {
    $join = [];
    if (!empty($this->options->join)) {
      foreach ($this->options->join as $joinItem) {
        $type = $joinItem['type'];
        $table = $joinItem['table'];
        $condition = $joinItem['localKey'] . $joinItem['operator'] . $joinItem['foreignKey'];
        $join[] = "$type JOIN $table ON $condition";
      }
    }
    return implode(' ', $join);
  }

  /**
   * 解析union 语句
   *
   * @return string
   */
  private function parseUnion(): string
  {
    if (empty($this->options->unions)) return '';
    $unionSql = []; // 初始化 SQL 字符串
    foreach ($this->options->unions as $union) {
      $query = $union['query'];
      $type = $union['type'];
      $unionSql[] = "$type ($query)";
    }
    return implode(' ', $unionSql);
  }

  /**
   * 解析Group语句
   *
   * @return string
   */
  protected function parseGroupBy(): string
  {
    if (!empty($this->options->groupBy)) {
      $group = array_map(function ($item) {
        return $this->quote($item);
      }, $this->options->groupBy);
      return 'GROUP BY ' . implode(', ', $group);
    }
    return '';
  }

  /**
   * 解析Having语句
   *
   * @return string
   */
  protected function parseHaving(): string
  {
    if (empty($this->options->having)) return '';
    $havingClauses = [];
    foreach ($this->options->having as $clause) {
      $col = $clause['column'];
      $op = $clause['operator'];
      $this->params[] = $clause['value'];
      $connector = $clause['connector'];
      $havingClauses[] = "$connector $col $op ?";
    }
    $sql = implode(' ', $havingClauses);
    $sql = preg_replace('/^(AND |OR )/', '', $sql);
    return 'HAVING ' . $sql;
  }

  /**
   * 解析排序语句
   *
   * @return string
   */
  protected function parseOrderBy(): string
  {
    if (!empty($this->options->orderBy)) {
      $order = [];
      foreach ($this->options->orderBy as $item) {
        if ($item instanceof Raw) {
          $this->params = array_merge($this->params, $item->bindings);
          $order[] = trim($item->sql);
        } else {
          $col = $item['column'];
          $direction = $item['direction'];
          $order[] = $this->quote($col) . ' ' . $direction;
        }
      }
      return 'ORDER BY ' . implode(', ', $order);
    }
    return '';
  }

  /**
   * 解析Limit语句
   *
   * @return string
   */
  protected function parseLimit(): string
  {
    return $this->options->limit ? 'LIMIT ' . $this->options->limit : '';
  }

  /**
   * 排他锁
   *
   * @return string
   */
  protected function parseLockForUpdate(): string
  {
    return $this->options->lockForUpdate ? ' FOR UPDATE' : '';
  }

  /**
   * 共享锁
   *
   * @return string
   */
  protected function parseSharedLock(): string
  {
    return $this->options->sharedLock ? ' LOCK IN SHARE MODE' : '';
  }
}
