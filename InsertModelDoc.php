<?php


namespace app\admin\command;


use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Db;
use think\Model;

/**
 * Class InsertModelDoc
 * @package app\admin\command
 */
class InsertModelDoc extends Command
{
    protected function configure()
    {
        $this->setName('insert_model_doc')->setDescription('插入模型类注释')
            ->addOption('app', 'a', Option::VALUE_REQUIRED, '应用目录', null)
            ->addOption('models', 'm', Option::VALUE_REQUIRED, '模型名称', null)
            ->setDescription('批量插入模型类注释');
    }

    protected function execute(Input $input, Output $output)
    {
        $app = $input->getOption('app');
        if ($app) {
            $paths = [APP_PATH . $app];
        } else {
            $paths = glob(APP_PATH . '*');
        }
        $models = $input->getOption('models');
        $models = $models ? explode(',', $models) : [];
        foreach ($paths as $path) {
            $modelPath = $path . '/model';
            if (is_dir($path) && is_dir($modelPath)) {
                $files = self::getModelFiles($modelPath, $models);
                foreach ($files as $file) {
                    $namespace = str_replace(rtrim(APP_PATH, DS), Config::get('app_namespace'), dirname($file));
                    /* @var Model $class */
                    $className = basename($file, '.php');
                    $class = str_replace(DS, '\\', DS . $namespace . DS . $className);
                    if (is_callable($class, 'getFieldsType')) {
                        $tableName = $class::getTable();
                        if ($tableName) {
                            $fields = '';
                            $class_doc = '';
                            try {
                                $reflection = new \ReflectionClass($class);
                                $class_doc = $reflection->getDocComment();
                                $fields = Db::query('SHOW FULL COLUMNS FROM ' . $class::getTable());
                            } catch (\ReflectionException $e) {
                            } catch (\Exception $e) {
                            }
                            if ($fields) {
                                $fields_docs = self::parseType($fields);
                                $file_content = file_get_contents($file);
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
                                        " * Class {$className}",
                                        " * @package {$namespace}"
                                    ];
                                }
                                $docs = array_merge(['/**'], $class_doc, $fields_docs, [' */']);
                                $docs = "\r\n\r\n" . implode("\r\n", $docs) . "\r\nclass {$className}";

                                $file_content = preg_replace("/(\s*\/\*{2}[\s\S]+?\*\/\s+)*?class\s+?{$className}/", $docs, $file_content);
                                file_put_contents($file, $file_content);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
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

    public static function getModelFiles($path, $names = [], &$files = [])
    {
        if (is_dir($path)) {
            //1、首先先读取文件夹
            $temp = scandir($path);
            //遍历文件夹
            foreach ($temp as $v) {
                //如果是文件夹则执行
                if ($v === '.' || $v === '..') {
                    //判断是否为系统隐藏的文件.和..  如果是则跳过否则就继续往下走，防止无限循环再这里。
                    continue;
                }
                $filepath = $path . '/' . $v;
                if (is_dir($filepath)) {
                    self::getModelFiles($filepath, $names, $files);
                } else {
                    $name = basename($filepath, '.php');
                    if ($names && !in_array($name, $names, true)) {
                        continue;
                    }
                    $files[] = $filepath;
                }
            }
        }
        return $files;
    }
}