# think-php-model-doc

#### 介绍
tp5 命令行一键添加Model类字段属性注释，解决phpstorm等IDE没有提示的问题


#### 安装教程

1.  复制InsertModelDoc.php到app应用目录，注意对应命名空间
2.  修改../application/command.php  添加 'app\admin\command\InsertModelDoc'

#### 使用说明

~~~bash
php think insert_model_doc
~~~

#### 可选参数
###### -a 应用目录
~~~bash
# 修改common目录下的所有模型 
php think insert_model_doc -a common 
~~~

> -m 模型名称
例如：php think insert_model_doc -m User 
修改所有应该目录下的User模型 

php think insert_model_doc -a common -m User
修改common目录下的User模型 
