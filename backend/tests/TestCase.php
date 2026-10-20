<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Laravel's process environment can otherwise override phpunit.xml inside Docker.
        putenv('APP_ENV=testing');
        putenv('DB_DATABASE=dataflow_test');
        putenv('DB_URL=');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'dataflow_test';
        $_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
        $app = parent::createApplication();
        $config = $app['config'];
        if ($config->get('database.default') !== 'pgsql' || $config->get('database.connections.pgsql.database') !== 'dataflow_test' || $config->get('database.connections.pgsql.url')) {
            throw new RuntimeException('Refusing to run tests outside dataflow_test.');
        }
        $database = $app['db']->connection()->selectOne('select current_database() as name')->name;
        if ($database !== 'dataflow_test') {
            throw new RuntimeException('Refusing to run tests outside dataflow_test.');
        }

        return $app;
    }
}
