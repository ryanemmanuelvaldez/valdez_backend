<?php

class RentRemindersCommand
{
    public static $command = 'rent:reminders';
    public static $description = 'Create rent due-date reminders for tenants and owners';
    public static $arguments = [];

    public function handle()
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Rent reminders can only be generated from the command line.');
        }

        if (!defined('PREVENT_DIRECT_ACCESS')) define('PREVENT_DIRECT_ACCESS', TRUE);
        if (!defined('ROOT_DIR')) define('ROOT_DIR', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        if (!defined('SYSTEM_DIR')) define('SYSTEM_DIR', ROOT_DIR . 'scheme' . DIRECTORY_SEPARATOR);

        global $argv;
        $original_argv = $argv;
        $argv[1] = '/';
        ob_start();
        require_once SYSTEM_DIR . 'kernel/LavaLust.php';
        ob_end_clean();
        $argv = $original_argv;

        require_once APP_DIR . 'libraries' . DIRECTORY_SEPARATOR . 'RentReminderService.php';
        $created = (new RentReminderService(lava_instance()->call->database()))->generate();
        echo $created . ' rent reminder' . ($created === 1 ? '' : 's') . ' created.' . PHP_EOL;
    }
}
