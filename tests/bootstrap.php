<?php
/**
 * Test bootstrap: boots the app against the TEST database (TEST_DB_NAME,
 * default chroma_test), migrates it and seeds reference data. Tests never
 * touch the production database: DB_NAME is overridden here before boot.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Chroma\Core\App;
use Chroma\Core\Config;
use Chroma\Core\Migrator;
use Chroma\Db\Seeder;

$root = dirname(__DIR__);
if (getenv('CHROMA_CONFIG') === false && is_file($root . '/config.env')) {
    putenv('CHROMA_CONFIG=' . $root . '/config.env');
}
$cfg = Config::load(null, $root);
$testDb = $cfg->get('TEST_DB_NAME', 'chroma_test');
if ($testDb === $cfg->get('DB_NAME', 'chroma')) {
    fwrite(STDERR, "Refusing to run tests: TEST_DB_NAME must differ from DB_NAME.\n");
    exit(1);
}
putenv('DB_NAME=' . $testDb);
// Per-user scratch dirs so a suite run as root never blocks a later run as the app user (the updater runs it as chroma).
$scratch = sys_get_temp_dir() . '/chroma-test-' . (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
putenv('CHROMA_TEST_TMP=' . $scratch);
putenv('LOG_PATH=' . $scratch . '/logs');
putenv('FILES_PATH=' . $scratch . '/files');
@mkdir($scratch, 0700, true);
$_ENV['DB_NAME'] = $testDb;

$app = App::boot($root);
(new Migrator($app->db, $root . '/db/migrations'))->run();
(new Seeder($app->db))->run();
