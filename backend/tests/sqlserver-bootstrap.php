<?php

if (getenv('DB_CONNECTION') !== 'sqlsrv' || ! preg_match('/^cpstl_kpi_test_[a-z0-9_]+$/D', getenv('DB_DATABASE') ?: '') || getenv('DB_URL') || is_file(__DIR__.'/../bootstrap/cache/config.php')) {
    throw new RuntimeException('SQL Server tests require an isolated cpstl_kpi_test_* database, no DB_URL and no cached configuration. Office databases are forbidden.');
}
require __DIR__.'/../vendor/autoload.php';
