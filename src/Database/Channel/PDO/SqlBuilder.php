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

use InvalidArgumentException;
use PDO;
use PDOException;
use Viswoole\Core\Common\Arr;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Query\WhereGroup;
use Viswoole\Database\Raw;

/**
 * SQL 语句构建器
 *
 * 根据查询选项（Options）生成参数化 SQL 并收集绑定参数，
 * 支持 SELECT / INSERT / UPDATE / DELETE 四种操作及各类子句。
 */
class SqlBuilder
{
  /**
   * @var array<string,array{left:string,right:string}> 各驱动对应的标识符包裹符号
   */
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
   * @var array<string,string[]> 表字段列表缓存，键为"通道实例ID:表名"
   *
   * 必须按通道实例隔离：不同通道（库）可能存在同名表但结构不同，
   * 仅按表名缓存会导致跨库结构污染。
   */
  private static array $tableColumns = [];
  /**
   * @var array<int,mixed> 预编译参数绑定列表
   */
  protected array $params = [];

  /**
   * @param PDOChannel $channel 数据库通道实例，用于获取驱动类型与连接
   * @param Options $options 查询选项
   */
  public function __construct(protected PDOChannel $channel, protected Options $options)
  {
  }

  /**
   * 生成基本sql语句
   *
   * @return Raw
   * @throws DbException
   */
  public function build(): Raw
  {
    $sql = match ($this->options->type) {
      'insert', 'insertGetId' => $this->buildInsert(),
      'update' => $this->buildUpdate(),
      'delete' => $this->buildDelete(),
      'select' => $this->buildSelect(),
      default => throw new InvalidArgumentException('不受支持的CRUD操作类型'),
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
   * @throws DbException
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
    if (Arr::isIndexArray($this->options->data)) {
      // 批量写入：校验各行字段集合一致，避免以首行为准，静默丢弃后续行的多余字段
      foreach ($this->options->data as $index => $item) {
        $rowKeys = array_keys($item);
        if (array_diff($rowKeys, $keys) || array_diff($keys, $rowKeys)) {
          throw new InvalidArgumentException(
            "批量写入失败：第 {$index} 行字段与首行不一致"
          );
        }
      }
    }
    if (!$this->options->strict) {
      $tableColumns = $this->getTableColumns();
      $keys = array_filter($keys, function ($key) use ($tableColumns) {
        return in_array($key, $tableColumns);
      });
      // 过滤后无有效字段会生成 "INSERT INTO t () VALUES ()" 非法 SQL，构建期拦截
      if ($keys === []) {
        throw new InvalidArgumentException(
          "写入表 {$this->options->table} 失败：过滤后无有效字段"
        );
      }
      $keys = array_values($keys);
    }
    // 要写入的列
    $quotedKeys = array_map([$this, 'quote'], $keys);
    $columns = implode(', ', $quotedKeys);
    // 如果是索引数组，说明是批量写入
    if (Arr::isIndexArray($this->options->data)) {
      $rows = [];
      foreach ($this->options->data as $item) {
        $values = $this->parseDataToValues($keys, $item);
        $rows[] = '(' . implode(', ', $values) . ')';
      }
      $sql .= "$table ($columns) VALUES " . implode(', ', $rows);
    } else {
      // 单条记录写入
      $values = $this->parseDataToValues($keys, $this->options->data);
      $values = implode(', ', $values);
      $sql .= "$table ($columns) VALUES ($values)";
    }
    return $sql;
  }

  /**
   * 用数据库标识符包裹字段名/表名（按点号分段包裹，支持 table.column 语法）
   *
   * 仅放行合法标识符字符（字母、数字、下划线、$）与点号分段，其余一律抛异常。
   * 此前"遇特殊字符原样放行"的策略会让恶意列名（如排序/分组/列名参数被注入
   * 用户输入时）直接拼入 SQL 构成注入；聚合函数等原生片段请使用 Db::raw() 显式声明
   *
   * @param string $str 待包裹的标识符
   * @return string 包裹后的标识符（table.column 形式逐段包裹）
   * @throws InvalidArgumentException 标识符包含非法字符时抛出
   */
  protected function quote(string $str): string
  {
    $str = trim($str);
    if ($str === '') {
      throw new InvalidArgumentException('数据库标识符不能为空');
    }
    $type = $this->channel->type->name;
    $quoted = [];
    foreach (explode('.', $str) as $segment) {
      // 星号通配：支持 columns('*') 与 table.* 场景
      if ($segment === '*') {
        $quoted[] = '*';
        continue;
      }
      if (!preg_match('/^[A-Za-z0-9_$]+$/', $segment)) {
        throw new InvalidArgumentException(
          "非法的数据库标识符：{$str}（仅允许字母、数字、下划线，"
          . '原生片段请使用 Db::raw() 声明）'
        );
      }
      $quoted[] = self::TAG[$type]['left'] . $segment . self::TAG[$type]['right'];
    }
    return implode('.', $quoted);
  }

  /**
   * 包裹带别名的表名（table AS alias）
   *
   * @param string $table 表名，支持 table AS alias 语法
   * @return string 包裹后的表名片段
   * @throws InvalidArgumentException 标识符包含非法字符时抛出
   */
  protected function quoteTableWithAlias(string $table): string
  {
    if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $table, $matches)) {
      return $this->quote($matches[1]) . ' AS ' . $this->quote($matches[2]);
    }
    return $this->quote($table);
  }

  /**
   * 获取当前表的字段名列表，结果按"通道实例:表名"缓存
   *
   * @return string[] 字段名列表
   * @throws DbException 查询表结构失败时抛出
   */
  protected function getTableColumns(): array
  {
    $table = $this->options->table;
    // 缓存键包含通道实例标识：不同通道（库）可能存在同名表但结构不同
    $cacheKey = spl_object_id($this->channel) . ':' . $table;
    if (isset(self::$tableColumns[$cacheKey])) return self::$tableColumns[$cacheKey];
    // 修复#1: 对表名使用反引号包裹，防止SQL注入风险
    $quotedTable = $this->quote($table);
    $sql = match ($this->channel->type->value) {
      'sqlite' => "PRAGMA table_info($quotedTable)",
      'pgsql' => 'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
      'oci' => 'SELECT column_name FROM USER_TAB_COLUMNS WHERE table_name = ?',
      'sqlsrv' => 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?',
      default => "DESCRIBE $quotedTable"
    };
    $conn = $this->channel->pop('read');
    try {
      // 修复#1: pgsql/oci/sqlsrv 使用参数绑定代替直接拼接表名
      if (in_array($this->channel->type->value, ['pgsql', 'oci', 'sqlsrv'])) {
        $statement = $conn->prepare($sql);
        $statement->execute([$table]);
      } else {
        $statement = $conn->query($sql);
      }
      if ($this->channel->type === DriverType::SQLite) {
        $fields = $statement->fetchAll(PDO::FETCH_ASSOC);
        $fields = array_column($fields, 'name');
      } else {
        $fields = $statement->fetchAll(PDO::FETCH_COLUMN);
      }
    } catch (PDOException $e) {
      // 异常模式下（ERRMODE_EXCEPTION）查询失败抛 PDOException，
      // 统一包装为携带 SQL 信息的 DbException，满足模块异常契约
      throw new DbException(
        "获取表 {$table} 字段失败（表可能不存在）：{$e->getMessage()}",
        $e->getCode(),
        $sql,
        $e
      );
    } finally {
      // 成功与失败路径都必须归还连接，防止异常时连接泄漏
      $this->channel->put($conn);
    }
    // 静默模式下查询失败返回 false；或查询成功但表无字段（表不存在）
    if (!$fields) throw new DbException("获取表 {$table} 字段失败，表可能不存在", 0, $sql);
    self::$tableColumns[$cacheKey] = $fields;
    return $fields;
  }

  /**
   * 清空表结构静态缓存
   *
   * 表结构在进程内长期缓存（strict 检测、字段过滤依赖），
   * ALTER TABLE 等结构变更后需调用本方法使缓存失效；
   * 亦用于测试隔离，避免跨用例污染。
   */
  public static function flushTableColumnsCache(): void
  {
    self::$tableColumns = [];
  }

  /**
   * 将一行数据按指定字段顺序转为占位符值列表，同时收集绑定参数
   *
   * @param string[] $keys 字段名列表
   * @param array $row 单行数据
   * @return string[] 占位符值列表（? 或 Raw SQL）
   */
  private function parseDataToValues(array $keys, array $row): array
  {
    $values = [];
    foreach ($keys as $key) {
      $value = $row[$key] ?? null;
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
   * 构建 UPDATE 语句
   *
   * @return string 生成的 UPDATE SQL
   * @throws DbException 获取表字段失败时抛出
   */
  public function buildUpdate(): string
  {
    $table = $this->quote($this->options->table);
    $sql = [];
    // UPDATE 子句
    $sql[] = "UPDATE $table SET";
    // 数据
    $sql[] = $this->parseUpdateData();
    // WHERE 子句
    $sql[] = $this->parseWhere();
    // LIMIT 子句
    $sql[] = $this->parseLimitAndOffset();
    // 移除空字符串项
    $sql = array_filter($sql, function ($item) {
      return !empty($item);
    });
    // 拼接 SQL 语句
    return implode(' ', $sql);
  }

  /**
   * 解析更新数据为 SET 子句，若无 WHERE 条件则尝试用主键自动补充
   *
   * @return string SET 子句内容
   * @throws DbException 获取表字段失败时抛出
   * @throws InvalidArgumentException 既无 WHERE 条件也无主键值时抛出
   */
  protected function parseUpdateData(): string
  {
    $data = $this->options->data;
    $sql = [];
    foreach ($data as $key => $value) {
      // 如果表不存在该字段，则跳过
      if (!$this->options->strict && !in_array($key, $this->getTableColumns())) continue;
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
        // column 保存原始列名，由 parseWhereItem 统一 quote，避免二次包裹
        $this->options->where[] = [
          'column' => $this->options->pk,
          'operator' => '=',
          'value' => $data[$this->options->pk],
          'connector' => 'AND'
        ];
      } else {
        $pk = $this->quote($this->options->pk);
        throw new InvalidArgumentException(
          "更新数据时，必须指定where条件,或在更新数据中包含{$pk}主键值"
        );
      }
    }
    return implode(', ', $sql);
  }

  /**
   * 解析 WHERE 子句，将条件列表拼接为 WHERE ... 字符串
   *
   * @return string WHERE 子句，无条件时返回空字符串
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
          throw new InvalidArgumentException(
            '当where条件值为null时，有效的运算符为=,!=,<>,IS NULL,IS NOT NULL'
          );
        }
        return $where;
      }
      if (is_array($value)) {
        // 数组值仅支持集合/区间类运算符：显式三参调用传非法组合（如 '=' + 数组）
        // 会生成 "col = (?, ?)" 坏 SQL，构建期拦截给出明确错误
        if (!in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
          throw new InvalidArgumentException(
            "运算符 {$operator} 不支持数组值，仅支持 IN/NOT IN/BETWEEN/NOT BETWEEN"
          );
        }
        // BETWEEN 语法为 "col BETWEEN ? AND ?"，不能复用 IN 的 (?, ?) 形式
        if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
          if (count($value) !== 2) {
            throw new InvalidArgumentException(
              "{$operator} 条件必须且只能提供两个边界值"
            );
          }
          $this->params[] = $value[0];
          $this->params[] = $value[1];
          return "$connector $column $operator ? AND ?";
        }
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
   * 解析 WHERE 条件组，将组内条件用括号包裹
   *
   * @param WhereGroup $where 条件分组
   * @return string 拼接后的 SQL 片段（含前导连接符 AND / OR）
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
   * 解析Limit和Offset
   *
   * @return string
   */
  protected function parseLimitAndOffset(): string
  {
    $sql = [];
    if (!is_null($this->options->limit)) {
      $sql[] = 'LIMIT ' . $this->options->limit;
    }
    if (!is_null($this->options->offset)) {
      $sql[] = 'OFFSET ' . $this->options->offset;
    }
    return implode(' ', $sql);
  }

  /**
   * 打包删除语句
   *
   * @return string
   */
  public function buildDelete(): string
  {
    $table = $this->quote($this->options->table);
    $sql = [];
    // DELETE FROM 子句
    $sql[] = "DELETE FROM $table";
    // WHERE 子句
    $sql[] = $this->parseWhere();
    // LIMIT 子句
    $sql[] = $this->parseLimitAndOffset();
    // 移除空字符串项
    $sql = array_filter($sql, function ($item) {
      return !empty($item);
    });
    // 拼接 SQL 语句
    return implode(' ', $sql);
  }

  /**
   * 构建Select语句
   *
   * @return string
   * @throws DbException
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
    $sql[] = $this->parseLimitAndOffset();
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
   * @throws DbException
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
      // 排除全部字段会生成 "SELECT  FROM" 非法 SQL，构建期拦截
      if ($fullFields === []) {
        throw new InvalidArgumentException(
          "withoutColumns 排除了表 {$this->options->table} 的全部字段，请至少保留一个字段"
        );
      }
      // 将索引数组转换为关联数组
      $fullFields = array_combine($fullFields, array_fill(0, count($fullFields), null));
      array_walk($fullFields, function (&$item, $key) {
        if (isset($fields[$key])) $item = $fields[$key];
      });
      $fields = $fullFields;
    }
    $parsedFields = [];
    foreach ($fields as $key => $value) {
      // 聚合查询等原生片段经 Raw 显式传入（int 键 + Raw 值），直接拼接
      if ($value instanceof Raw) {
        $this->params = array_merge($this->params, $value->bindings);
        $parsedFields[] = $value->sql;
        continue;
      }
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
   * 解析强制索引子句，仅 MySQL 和 SQLite 支持
   *
   * @return string FORCE INDEX / INDEXED BY 子句，未指定时返回空字符串
   */
  protected function parseForce(): string
  {
    if (empty($this->options->force)) return '';
    $index = $this->quote($this->options->force);
    return match ($this->channel->type) {
      DriverType::MYSQL => "FORCE INDEX($index)",
      // 修复: SQLite 使用 INDEXED BY 语法，而非错误的 "a WITH (INDEX(...))" (那是 SQL Server 语法)
      DriverType::SQLite => "INDEXED BY $index",
      default => '',
    };
  }

  /**
   * 解析 JOIN 子句列表
   *
   * @return string 拼接后的 JOIN 子句，无 JOIN 时返回空字符串
   */
  protected function parseJoin(): string
  {
    $join = [];
    if (!empty($this->options->join)) {
      foreach ($this->options->join as $joinItem) {
        $type = $joinItem['type'];
        // 修复#3: 对join中的表名和字段名使用反引号包裹，防止SQL注入风险；
        // 表名支持 table AS alias 语法，经 quoteTableWithAlias 逐段包裹
        $table = $this->quoteTableWithAlias($joinItem['table']);
        $localKey = $this->quote($joinItem['localKey']);
        $operator = $joinItem['operator'];
        $foreignKey = $this->quote($joinItem['foreignKey']);
        $condition = $localKey . $operator . $foreignKey;
        $join[] = "$type JOIN $table ON $condition";
      }
    }
    return implode(' ', $join);
  }

  /**
   * 解析 UNION 合并查询子句
   *
   * @return string 拼接后的 UNION 子句，无合并查询时返回空字符串
   */
  private function parseUnion(): string
  {
    if (empty($this->options->unions)) return '';
    $unionSql = []; // 初始化 SQL 字符串
    foreach ($this->options->unions as $union) {
      $query = $union['query'];
      $type = $union['type'];
      // 修复: 当 query 为 Raw 对象时，需要收集其绑定参数到 params，否则参数会丢失导致 SQL 执行错误
      if ($query instanceof Raw) {
        $this->params = array_merge($this->params, $query->bindings);
        $query = $query->sql;
      }
      $unionSql[] = "$type ($query)";
    }
    return implode(' ', $unionSql);
  }

  /**
   * 解析 GROUP BY 子句
   *
   * @return string GROUP BY 子句，未分组时返回空字符串
   */
  protected function parseGroupBy(): string
  {
    if (!empty($this->options->groupBy)) {
      // 修复#16: 对GROUP BY字段使用反引号包裹，防止SQL注入风险
      $group = array_map(function ($item) {
        return $this->quote($item);
      }, $this->options->groupBy);
      return 'GROUP BY ' . implode(', ', $group);
    }
    return '';
  }

  /**
   * 解析 HAVING 子句
   *
   * @return string HAVING 子句，无条件时返回空字符串
   */
  protected function parseHaving(): string
  {
    if (empty($this->options->having)) return '';
    $havingClauses = [];
    foreach ($this->options->having as $clause) {
      // 修复#2: 对having条件中的字段名使用反引号包裹，防止SQL注入风险
      $col = $this->quote($clause['column']);
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
   * 解析 ORDER BY 子句，支持 Raw 原始排序表达式
   *
   * @return string ORDER BY 子句，未排序时返回空字符串
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
          // 修复#10: 对排序字段使用反引号包裹，防止ORDER BY SQL注入风险
          $order[] = $this->quote($col) . ' ' . $direction;
        }
      }
      return 'ORDER BY ' . implode(', ', $order);
    }
    return '';
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
   * 解析共享锁（LOCK IN SHARE MODE）子句
   *
   * @return string LOCK IN SHARE MODE 后缀，未启用时返回空字符串
   */
  protected function parseSharedLock(): string
  {
    return $this->options->sharedLock ? ' LOCK IN SHARE MODE' : '';
  }
}
