# OSS Adapter for Flysystem

```
composer require hyperf/flysystem-oss
```

## 鸣谢

借鉴了 [xxtime/flysystem-aliyun-oss](https://github.com/xxtime/flysystem-aliyun-oss) 部分代码，在此表示感谢。

## 使用

```php
<?php

$adapter = new Adapter([
    'accessId' => env('OSS_ACCESS_ID'),
    'accessSecret' => env('OSS_ACCESS_SECRET'),
    'bucket' => env('OSS_BUCKET'),
    'endpoint' => env('OSS_ENDPOINT'),
    'timeout' => 3600,
    'connectTimeout' => 10,
    'isCName' => false,
    'token' => null,
    'proxy' => null,
]);
$flysystem = new Filesystem($adapter);
$flysystem->write('test.json', Json::encode(['id' => uniqid()]));
```
