<?php
declare (strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Class InsertModelDoc
 * @package app\command
 */
class InsertModelDoc extends Command
{
    private $depth = 5;

    private $app_name = ['application', 'app'];

    protected function configure()
    {
        // 指令配置
        $this->setName('model_doc')
            ->addOption('path', 'p', Option::VALUE_REQUIRED, '模型目录', null)
            ->addOption('models', 'm', Option::VALUE_REQUIRED, '模型名称', null)
            ->setDescription('批量插入模型类注释');
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        $path = $input->getOption('path');
        $models = $input->getOption('models');
        $models = $models ? explode(',', $models) : [];
        $appPath = $this->getAppPath();
        if (!$appPath) {
            $output->error('找不到应用目录');
            return false;
        }
        $path = $appPath . ($path ? DIRECTORY_SEPARATOR . str_replace(['/', '\\'], '', $path) : '');
        if (!is_dir($path)) {
            $output->error('不是有效的目录');
            return false;
        }
        $files = self::getFiles($path, $models);
        if (!$files) {
            $output->error('没有找到模型文件，检查路径是否错误：' . $path);
            return false;
        }
        /** @var object $db */
        $db = '';
        foreach ([['\think\Db', 'query'], ['\think\facade\Db', 'query']] as $item) {
            if (is_callable($item)) {
                $db = $item[0];
            }
        }
        if (!$db) {
            $output->error('没有找到ThinkPHP的Db类');
            return false;
        }
        $count = 0;
        foreach ($files as $file) {
            $modelClass = $this->getModelClass($file);
            if (!$modelClass || !$tableName = $modelClass['class']::getTable()) {
                continue;
            }
            $fields = '';
            $class_doc = '';
            try {
                $reflection = new \ReflectionClass($modelClass['class']);
                $class_doc = $reflection->getDocComment();
                $fields = $db::query('SHOW FULL COLUMNS FROM ' . $modelClass['class']::getTable());
            } catch (\ReflectionException $e) {
            } catch (\Exception $e) {
            }
            if ($fields) {
                $fields_docs = self::parseType($fields);
                if ($class_doc) {
                    foreach ($fields_docs as $f => $doc) {
                        $class_doc = preg_replace("/^\s*?\*{1}\s*?@property.*?{$f}.*$/m", '  ', $class_doc);
                    }
                    $class_doc = preg_replace('/^\s*?(\/\*+|[*\s]+\/)\S*?/m', "", $class_doc);
                    $class_doc = str_replace(["\r\n", "\n", "\r"], "\n", $class_doc);
                    $class_doc = preg_replace("/^\s+$/m", implode("\r\n", $fields_docs), $class_doc);
                    $class_doc = array_filter(explode("\n", $class_doc));
                } else {
                    $class_doc = [
                        " * Class {$modelClass['name']}",
                        " * @package {$modelClass['namespace']}"
                    ];
                }
                $docs = array_merge(['/**'], $class_doc, $fields_docs, [' */']);
                $docs = "\r\n\r\n" . implode("\r\n", $docs) . "\r\nclass {$modelClass['name']}";
                $file_content = file_get_contents($file);
                $new_content = preg_replace("/(\s*\/\*{2}[\s\S]+?\*\/\s+)*?class\s+?{$modelClass['name']}/", $docs, $file_content);
                if ($new_content !== $file_content) {
                    file_put_contents($file, $new_content);
                    $count++;
                    $output->comment($file);
                }
            }
        }
        $output->info("共修改{$count}个文件");
    }

    /**
     * 解析字段类型
     * @param $fields
     * @return array
     */
    public static function parseType($fields): array
    {
        $types = [
            'int'   => ['time', 'timestamp', 'tinyint', 'tinyblob'],
            'float' => ['decimal']
        ];
        $arr = [];
        foreach ($fields as $item) {
            $type = preg_replace('/^([a-z]+).*/', "$1", $item['Type']);
            $t = 'string';
            foreach ($types as $key => $value) {
                if (strpos($key, $type) !== false || strpos($type, $key) !== false || in_array($type, $value, true)) {
                    $t = $key;
                    break;
                }
            }
            $comment = preg_replace("/\s+/", ' ', $item['Comment']);
            $arr[$item['Field']] = " * @property {$t} {$item['Field']} {$comment}";
        }
        return $arr;
    }

    /**
     * 获取.php文件
     * @param $path
     * @param array $names
     * @return array
     */
    public static function getFiles($path, $names = []): array
    {
        $files = [];
        if (is_dir($path)) {
            $temp = scandir($path);
            foreach ($temp as $v) {
                if ($v === '.' || $v === '..') {
                    //判断是否为系统隐藏的文件.防止无限循环再这里。
                    continue;
                }
                $filepath = $path . '/' . $v;
                if (is_dir($filepath)) {
                    $list = self::getFiles($filepath, $names);
                    foreach ($list as $item) {
                        $files[] = $item;
                    }
                    continue;
                }
                $name = basename($filepath, '.php');
                if ($names && !in_array($name, $names, true)) {
                    continue;
                }
                $files[] = $filepath;
            }
        }
        return $files;
    }

    /**
     * 获取模型类
     * @param $file
     * @return array|false
     */
    public function getModelClass($file)
    {
        if (!is_file($file)) {
            return false;
        }
        $content = file_get_contents($file);
        preg_match('/namespace\s+(.+?);/', $content, $match);
        if (!$match || !isset($match[1])) {
            return false;
        }
        $className = basename($file, '.php');
        $class = $match[1] . '\\' . $className;
        $getFieldsType = [$class, 'getFieldsType'];
        $getTable = [$class, 'getTable'];
        if (!is_callable($getFieldsType) || !is_callable($getTable)) {
            return false;
        }
        return ['namespace' => $match[1], 'name' => $className, 'class' => $class];
    }

    /**
     * 获取应用目录
     * @return false|string
     */
    public function getAppPath()
    {
        $root = $this->getRootPath();
        if (!$root) {
            return false;
        }
        foreach ($this->app_name as $item) {
            $path = $root . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                return $path;
            }
        }
        $appName = $this->output->ask($this->input, '没有找到默认的应用目录（application或app），请指定应用目录名称');
        if ($appName) {
            $path = $root . DIRECTORY_SEPARATOR . $appName;
            if (is_dir($path)) {
                return $path;
            }
        }
        return false;
    }

    /**
     * 获取根目录
     * @param string $path
     * @return string
     */
    public function getRootPath($path = __DIR__): string
    {
        $think = $path . '/think';
        if (!is_file($think)) {
            if ($this->depth > 0) {
                $this->depth--;
                return $this->getRootPath(dirname($path));
            }
            return '';
        }
        return $path;
    }
}
