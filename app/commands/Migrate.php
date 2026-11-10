<?php

class MigrateCommand
{
    public static $command = 'db:migrate';
    public static $description = 'Run pending database migrations';
    public static $arguments = [];

    public function handle()
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Database migrations can only be run from the command line.');
        }

        if (!defined('PREVENT_DIRECT_ACCESS')) {
            define('PREVENT_DIRECT_ACCESS', TRUE);
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        }
        if (!defined('SYSTEM_DIR')) {
            define('SYSTEM_DIR', ROOT_DIR . 'scheme' . DIRECTORY_SEPARATOR);
        }

        global $argv;
        $original_argv = $argv;
        $argv[1] = '/';
        ob_start();
        require_once SYSTEM_DIR . 'kernel/LavaLust.php';
        ob_end_clean();
        $argv = $original_argv;

        $config = load_class('config', 'kernel');
        $config->load('migration');
        $config->set('migration_enabled', TRUE);
        get_config(['migration_enabled' => TRUE]);

        load_class('migration', 'libraries')->migrate();
    }
}
