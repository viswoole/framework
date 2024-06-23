<?php
declare (strict_types=1);

namespace Viswoole\Core\Console\Commands\Optimize;

use Override;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
  name       : 'optimize:facade',
  description: 'Build facade ide helper.',
  hidden     : false
)]
class Facade extends Command
{
  #[Override] protected function configure(): void
  {
    $this->addArgument(
      'namespace',
      InputArgument::REQUIRED,
      'The Facade class needs to be optimized with a fully qualified name'
    );
  }

  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    // 需要优化的facade类
    $namespace = $input->getArgument('namespace');
    $io = new SymfonyStyle($input, $output);
    try {
      $reflector = new ReflectionClass($namespace);
      $line = $reflector->getStartLine() - 1;
      $file = $reflector->getFileName();
      $method = $reflector->getMethod('getFacadeClass');
      // 得到映射的类
      $mapClass = $method->invoke(null);
      $mapClassReflector = new ReflectionClass($mapClass);
      $classDoc = $mapClassReflector->getDocComment();
      if (!$classDoc) {
        $classDoc = '';
      } elseif (preg_match('/^\s*\*\s*(.*?)\s*$/m', $classDoc, $matches)) {
        $classDoc = trim($matches[1]);
      }
      $methods = $mapClassReflector->getMethods(ReflectionMethod::IS_PUBLIC);
      $methodStrings = [];
      // 遍历并输出所有公共方法的名称
      foreach ($methods as $method) {
        $name = $method->getName();
        // 如果是魔术方法则跳出本循环
        if (str_starts_with($name, '__')) {
          continue;
        }
        $params = [];
        foreach ($method->getParameters() as $param) {
          $paramName = $param->getName();

          $paramType = (string)$param->getType();
          $paramType = $this->formatType($paramType, 'mixed');
          $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
          $params[$paramName] = [
            'type' => $paramType,
            'default' => !is_string($default) ? json_encode(
              $default, JSON_UNESCAPED_UNICODE
            ) : $default,
            'isDefaultValueAvailable' => $param->isDefaultValueAvailable()
          ];
        }
        $doc = $method->getDocComment();
        // 如果注释不存在，则返回空字符串
        if ($doc === false) {
          $doc = '';
        } elseif (preg_match('/^\s*\*\s*(.*?)\s*$/m', $doc, $matches)) {
          $doc = trim($matches[1]);
        }
        $returnType = (string)$method->getReturnType();
        $returnType = $this->formatType($returnType, 'void');
        $methodStrings[] = $this->parse([
          'name' => $name,
          'params' => $params,
          'doc' => $doc,
          'return' => $returnType
        ]);
      }

      $docMethodBody = implode("\n", $methodStrings);
      if (strlen($classDoc) > 0) {
        $classDoc = " * $classDoc\n *\n";
      }
      $modifiedStr = str_replace('\\', '\\\\', $namespace);
      $resultString = "/**\n$classDoc$docMethodBody\n *\n * 优化命令：php viswoole optimize:facade $modifiedStr\n */";
      $this->write($file, $line, $resultString);
      $io->success("已将@method注释写入到{$file}文件中\n务必检查语法是否正确");
    } catch (Throwable $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }
    return Command::SUCCESS;
  }

  /**
   * 格式化类型
   *
   * @param string $type
   * @param string $default
   * @return string
   */
  private function formatType(string $type, string $default): string
  {
    if (strlen($type) < 1) return $default;
    $types = explode('|', $type);
    foreach ($types as $key => $t) {
      $position = strpos($type, '\\');
      if ($position) {
        if (str_starts_with($t, '?')) {
          $t = substr_replace($t, '\\', 1, 0);
        } else {
          $t = "\\$t";
        }
      }
      $types[$key] = $t;
    }
    return implode('|', $types);
  }

  /**
   * 解析方法
   *
   * @param array $data
   * @return string
   */
  protected function parse(array $data): string
  {
    $params = '';
    foreach ($data['params'] as $key => $structure) {
      $params .= " {$structure['type']} $$key";
      if ($structure['isDefaultValueAvailable']) {
        $val = $structure['type'] === 'string' ? "'{$structure['default']}'" : $structure['default'];
        $params .= " = $val";
      }
      $params .= ',';
    }
    $params = ltrim($params, ' ');
    $params = rtrim($params, ',');
    $str = " * @method static {$data['return']} {$data['name']}($params)";
    if (strlen($data['doc']) > 0) {
      $str .= ' ' . $data['doc'];
    }
    return $str;
  }

  /**
   * 写入文件
   *
   * @param string $file
   * @param int $line
   * @param string $string
   * @return void
   */
  protected function write(string $file, int $line, string $string): void
  {
    // 读取文件内容
    $fileContents = file_get_contents($file);
    // 在指定行之前插入注解字符串
    $lines = explode(PHP_EOL, $fileContents);
    array_splice($lines, $line, 0, $string);
    $newFileContents = implode(PHP_EOL, $lines);
    // 将修改后的内容写回文件
    file_put_contents($file, $newFileContents);
  }
}
