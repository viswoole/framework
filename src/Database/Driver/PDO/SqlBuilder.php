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

namespace Viswoole\Database\Driver\PDO;

use PDO;
use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\Collector\Raw;
use Viswoole\Database\Exception\DbException;

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

  public function __construct(protected PDOChannel $driver, protected QueryOptions $options)
  {

  }

  public function build(bool $merge): string|array
  {

    return [];
  }

  /**
   * 解析通用的查询头
   *
   * @return string
   */
  private function parseQueryHead(): string
  {
    $operaType = $this->options->queryType;
    $table = $this->addIdentifier($this->options->prefix . $this->options->table);
    // 设置别名
    if ($this->options->alias) {
      $table = $table . ' AS ' . $this->addIdentifier($this->options->alias);
    }
    // 聚合查询的列
    $columnName = $this->options->columnName;
    if ($columnName !== '*') $columnName = $this->addIdentifier($columnName);
    // 查询的字段
    $field = $this->parseFields();
    // 写入方式
    $replace = 'INSERT';
    $isMysql = $this->driver->type === PDODriverType::MYSQL;
    if ($isMysql) {
      $replace = $this->options->replace ? 'REPLACE' : 'INSERT';
    }
    $DISTINCT = $this->options->distinct ? ' DISTINCT' : '';
    $PARTITION = '';
    if ($isMysql && !empty($this->options->partition)) {
      $partition = implode(',', $this->options->partition);
      $PARTITION = " PARTITION ($partition)";
    }
    $USE_INDEX = '';
    if (!empty($this->options->force)) {
      $force = $this->options->force;
      foreach ($force as $index) {
        $USE_INDEX .= " USE INDEX ($index)";
      }
    }
    return match ($operaType) {
      'UPDATE', 'INC', 'DEC' => /** @lang text */ "UPDATE $table SET",

      'DELETE' => /** @lang text */ "DELETE FROM $table",

      'INSERT' => /** @lang text */ "$replace   INTO $table",

      'COUNT' => /** @lang text */ "SELECT COUNT($columnName) AS DB_NUMBER FROM $table$PARTITION$USE_INDEX",

      'SUM' => /** @lang text */ "SELECT SUM($columnName) AS DB_NUMBER FROM $table$PARTITION$USE_INDEX",

      'AVG' => /** @lang text */ "SELECT AVG($columnName) AS DB_NUMBER FROM $table$PARTITION$USE_INDEX",

      'MIN' => /** @lang text */ "SELECT MIN($columnName) AS DB_NUMBER FROM $table$PARTITION$USE_INDEX",

      'MAX' => /** @lang text */ "SELECT MAX($columnName) AS DB_NUMBER FROM $table$PARTITION$USE_INDEX",

      default => /** @lang text */ "SELECT$DISTINCT $field FROM $table$PARTITION$USE_INDEX",
    };
  }

  /**
   * 用标识符包裹字段
   *
   * @param string $str
   * @return string
   */
  private function addIdentifier(string $str): string
  {
    return self::TAG[$this->driver->type->name]['left'] . $str . self::TAG[$this->driver->type->name]['right'];
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
    $table = $this->addIdentifier($this->options->prefix . $this->options->table);
    if (isset(self::$tableFields[$table])) return self::$tableFields[$table];
    $sql = match ($this->driver->type->value) {
      'sqlite' => "PRAGMA table_info([$table])",
      'pgsql' => /** @lang text */
      "SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND table_schema = 'public'",
      default => "DESCRIBE `$table`"
    };
    $statement = $this->driver->exec($sql);
    $fields = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$fields) throw new DbException('获取数据表结构失败', 10500, $sql);
    self::$tableFields[$table] = $fields;
    return $fields;
  }
}
