<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Flysystem\OSS;

use Hyperf\Utils\ResourceGenerator;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use OSS\OssClient;

class Adapter implements FilesystemAdapter
{
    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @param $config = [
     *     'accessId' => '',
     *     'accessSecret' => '',
     *     'bucket' => '',
     *     'endpoint' => '',
     *     'timeout' => 3600,
     *     'connectTimeout' => 10,
     *     'isCName' => false,
     *     'token' => '',
     *     'proxy' => null,
     * ]
     */
    public function __construct($config = [])
    {
        $this->bucket = $config['bucket'];
        $accessId = $config['accessId'];
        $accessSecret = $config['accessSecret'];
        $endpoint = $config['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com';
        $timeout = $config['timeout'] ?? 3600;
        $connectTimeout = $config['connectTimeout'] ?? 10;
        $isCName = $config['isCName'] ?? false;
        $token = $config['token'] ?? null;
        $proxy = $config['proxy'] ?? null;

        $this->client = make(OssClient::class, [
            $accessId,
            $accessSecret,
            $endpoint,
            $isCName,
            $token,
            $proxy,
        ]);

        $this->client->setTimeout($timeout);
        $this->client->setConnectTimeout($connectTimeout);
    }

    public function fileExists(string $path): bool
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->client->putObject($this->bucket, $path, $contents, $this->getOssOptions($config));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'The contents is invalid resource.');
        }
        $i = 0;
        $bufferSize = 1024 * 1024;
        while (! feof($contents)) {
            if (false === $buffer = fread($contents, $bufferSize)) {
                throw UnableToWriteFile::atLocation($path, 'fread failed');
            }
            $position = $i * $bufferSize;
            $this->client->appendObject($this->bucket, $path, $buffer, $position, $this->getOssOptions($config));
            ++$i;
        }
        fclose($contents);
    }

    public function read(string $path): string
    {
        return $this->client->getObject($this->bucket, $path);
    }

    public function readStream(string $path)
    {
        return ResourceGenerator::from($this->read($path));
    }

    public function delete(string $path): void
    {
        $this->client->deleteObject($this->bucket, $path);
    }

    public function deleteDirectory(string $path): void
    {
        $lists = $this->listContents($path, true);
        if (! $lists) {
            return;
        }
        $objectList = [];
        foreach ($lists as $value) {
            $objectList[] = $value['path'];
        }
        $this->client->deleteObjects($this->bucket, $objectList);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->client->createObjectDir($this->bucket, $path);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->client->putObjectAcl(
            $this->bucket,
            $path,
            ($visibility == 'public') ? 'public-read' : 'private'
        );
    }

    public function visibility(string $path): FileAttributes
    {
        $response = $this->client->getObjectAcl($this->bucket, $path);
        return new FileAttributes($path, null, $response);
    }

    public function mimeType(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        return new FileAttributes($path, null, null, null, $response['content-type']);
    }

    public function lastModified(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        return new FileAttributes($path, null, null, $response['last-modified']);
    }

    public function fileSize(string $path): FileAttributes
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);

        $fileSize = null;
        if (isset($response['content-length'])) {
            $fileSize = (int) $response['content-length'];
        }
        return new FileAttributes($path, $fileSize);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = rtrim($path, '\\/');

        $result = [];
        $nextMarker = '';
        while (true) {
            // max-keys 用于限定此次返回object的最大数，如果不设定，默认为100，max-keys取值不能大于1000。
            // prefix   限定返回的object key必须以prefix作为前缀。注意使用prefix查询时，返回的key中仍会包含prefix。
            // delimiter是一个用于对Object名字进行分组的字符。所有名字包含指定的前缀且第一次出现delimiter字符之间的object作为一组元素
            // marker   用户设定结果从marker之后按字母排序的第一个开始返回。
            $options = [
                'max-keys' => 1000,
                'prefix' => $directory . '/',
                'delimiter' => '/',
                'marker' => $nextMarker,
            ];
            $res = $this->client->listObjects($this->bucket, $options);

            // 得到nextMarker，从上一次$res读到的最后一个文件的下一个文件开始继续获取文件列表
            $nextMarker = $res->getNextMarker();
            $prefixList = $res->getPrefixList(); // 目录列表
            $objectList = $res->getObjectList(); // 文件列表
            if ($prefixList) {
                foreach ($prefixList as $value) {
                    $result[] = [
                        'type' => 'dir',
                        'path' => $value->getPrefix(),
                    ];
                    if ($deep) {
                        $result = array_merge($result, $this->listContents($value->getPrefix(), $deep));
                    }
                }
            }
            if ($objectList) {
                foreach ($objectList as $value) {
                    if (($value->getSize() === 0) && ($value->getKey() === $directory . '/')) {
                        continue;
                    }
                    $result[] = [
                        'type' => 'file',
                        'path' => $value->getKey(),
                        'timestamp' => strtotime($value->getLastModified()),
                        'size' => $value->getSize(),
                    ];
                }
            }
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->client->copyObject($this->bucket, $source, $this->bucket, $destination);
        $this->client->deleteObject($this->bucket, $source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->client->copyObject($this->bucket, $source, $this->bucket, $destination);
    }

    private function getOssOptions(Config $config): array
    {
        $options = [];
        if ($headers = $config->get('headers')) {
            $options['headers'] = $headers;
        }

        if ($contentType = $config->get('Content-Type')) {
            $options['Content-Type'] = $contentType;
        }

        if ($contentMd5 = $config->get('Content-Md5')) {
            $options['Content-Md5'] = $contentMd5;
            $options['checkmd5'] = false;
        }
        return $options;
    }
}
