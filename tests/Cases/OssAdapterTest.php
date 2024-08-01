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

namespace HyperfTest\Cases;

use Hyperf\Codec\Json;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Flysystem\OSS\Adapter;
use Hyperf\Support\ResourceGenerator;
use League\Flysystem\Filesystem;
use OSS\OssClient;

/**
 * @internal
 * @coversNothing
 */
class OssAdapterTest extends AbstractTestCase
{
    protected $bucket = 'hyperf';

    protected function tearDown(): void
    {
        \Mockery::close();
    }

    public function testWrite()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('putObject')->withAnyArgs()->once()->andReturnNull();
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $flysystem->write('test.json', Json::encode(['id' => uniqid()]));
        $this->assertTrue(true);
    }

    public function testFileExists()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('doesObjectExist')->with($this->bucket, 'test.json')->once()->andReturnTrue();
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $this->assertTrue($flysystem->fileExists('test.json'));
    }

    public function testWriteStream()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('appendObject')->withAnyArgs()->once()->andReturnNull();
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $flysystem->writeStream('test3.json', ResourceGenerator::from(Json::encode(['name' => uniqid()])));
        $this->assertTrue(true);
    }

    public function testGetObject()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('getObject')->with($this->bucket, 'test.json')->once()->andReturn('{}');
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $this->assertSame('{}', $flysystem->read('test.json'));
    }

    public function testSignUrl()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('signUrl')->with($this->bucket, 'test.json', 3600)->once()->andReturn('test');
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $expiresAt = \Mockery::mock(\DateTime::class);
        $this->assertSame('test', $flysystem->temporaryUrl('test.json', $expiresAt));
    }

    public function testDelete()
    {
        $client = $this->getDefaultOssClient();
        $client->shouldReceive('deleteObject')->with($this->bucket, 'test.json')->once()->andReturnNull();
        $adapter = new Adapter($this->getDefaultOptions());
        $ref = new \ReflectionClass($adapter);
        $p = $ref->getProperty('client');
        $p->setValue($adapter, $client);

        $flysystem = new Filesystem($adapter);
        $flysystem->delete('test.json');
        $this->assertTrue(true);
    }

    public function testSetTimeout()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(OssClient::class, \Mockery::any())->andReturnUsing(function ($_, $args) {
            $client = \Mockery::mock(OssClient::class);
            $client->shouldReceive('setTimeout')->with(3600)->once()->andReturnNull();
            $client->shouldReceive('setConnectTimeout')->with(10)->once()->andReturnNull();
            return $client;
        });
        $adapter = new Adapter($this->getDefaultOptions());
        new Filesystem($adapter);

        $container = $this->getContainer();
        $container->shouldReceive('make')->with(OssClient::class, \Mockery::any())->andReturnUsing(function ($_, $args) {
            $client = \Mockery::mock(OssClient::class);
            $client->shouldReceive('setTimeout')->with(1000)->once()->andReturnNull();
            $client->shouldReceive('setConnectTimeout')->with(20)->once()->andReturnNull();
            return $client;
        });
        $adapter = new Adapter([
            'accessId' => 'xxx',
            'accessSecret' => 'xxx',
            'bucket' => $this->bucket,
            'endpoint' => 'oss-cn-qingdao.aliyuncs.com',
            'timeout' => 1000,
            'connectTimeout' => 20,
        ]);
        new Filesystem($adapter);
        $this->assertTrue(true);
    }

    protected function getDefaultOssClient()
    {
        $client = \Mockery::mock(OssClient::class);
        $client->shouldReceive('setTimeout')->with(3600)->andReturnNull();
        $client->shouldReceive('setConnectTimeout')->with(10)->andReturnNull();
        return $client;
    }

    protected function getDefaultOptions(): array
    {
        return [
            'accessId' => 'xxx',
            'accessSecret' => 'xxx',
            'bucket' => $this->bucket,
            'endpoint' => 'oss-cn-qingdao.aliyuncs.com',
        ];
    }

    protected function getContainer()
    {
        $container = \Mockery::mock(Container::class);
        ApplicationContext::setContainer($container);
        return $container;
    }
}
