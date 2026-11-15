<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 测试必须使用内存 SQLite。
     *
     * RefreshDatabase 会在 parent::setUp() 中执行 migrate:fresh，
     * 所以在启动应用前校验，避免容器注入的 DB_* 把开发库清空。
     */
    protected function setUp(): void
    {
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        $database = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            $this->fail('测试必须使用 sqlite 内存库：请检查 phpunit.xml，不要连接开发数据库。');
        }

        parent::setUp();
    }
}
