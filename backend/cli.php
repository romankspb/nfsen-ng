#!/usr/bin/env php
<?php
spl_autoload_register(function ($class) {
    $class = strtolower(str_replace('nfsen_ng\\', '', $class));
    include_once __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
});

use nfsen_ng\common\{Debug, Config, Import};

$d = Debug::getInstance();
try {
    Config::initialize();
} catch (Exception $e) {
    $d->log('Fatal: ' . $e->getMessage(), LOG_ALERT);
    exit();
}

if ($argc < 2 || in_array($argv[1], array('--help', '-help', '-h', '-?'))) {
    ?>

    This is the command line interface to nfsen-ng.

    Usage:
    <?php echo $argv[0]; ?> [options] import
    <?php echo $argv[0]; ?> start|stop|status

    Options:
    -v  Show verbose output
    -p  Import ports data
    -ps Import ports data per source
    -f  Force overwriting database and start at the beginning

    Commands:
    import  - Import existing nfdump data to nfsen-ng.
    Notice: If you have existing nfcapd files, better do this overnight.
    start   - Start the daemon for continuous reading of new data
    stop    - Stop the daemon
    status  - Get the daemon's status

    Examples:
    <?php echo $argv[0]; ?> -f import
    Imports fresh data for sources
    
    <?php echo $argv[0]; ?> -s -p import
    Imports data for ports only
    
    <?php echo $argv[0]; ?> start
    Start the daemon
    
    <?php
} else {
    //$folder = dirname(__FILE__);
    $folder = '/var/nfdump/profiles-data';
    $pidfile = $folder . '/nfsen-ng.pid';
    
    if (in_array('import', $argv)) {
        
        // import 3 years of data if available
        
        $d->log('CLI: Starting import', LOG_INFO);
        $start = new DateTime();
        $start->setDate(date('Y') - 3, date('m'), date('d'));
        $i = new Import();
        if (in_array('-v', $argv)) $i->setVerbose(true);
        if (in_array('-p', $argv)) $i->setProcessPorts(true);
        if (in_array('-ps', $argv)) $i->setProcessPortsBySource(true);
        if (in_array('-f', $argv)) $i->setForce(true);
        $i->start($start);
        
    } elseif (in_array('start', $argv)) {
        
        // start the daemon
        
        $d->log('CLI: Starting daemon...', LOG_INFO);
        $pid = exec('nohup `which php` ' . $folder . '/listen.php > /dev/null 2>&1 & echo $!', $op, $exit);
        var_dump($exit);
        // todo: get exit code of background process. possible at all?
        switch (intval($exit)) {
            case 128:
                echo 'Unexpected error opening or locking lock file. Perhaps you don\'t have permission to write to the lock file or its containing directory?';
                break;
            case 129:
                echo 'Another instance is already running; terminating.';
                break;
            default:
                echo 'Daemon running, pid=' . $pid;
                break;
        }
        echo PHP_EOL;
        
    } elseif (in_array('stop', $argv)) {
        
        // stop the daemon
        
        if (!file_exists($pidfile)) {
            echo "Not running" . PHP_EOL;
            exit();
        }
        $pid = file_get_contents($pidfile);
        $d->log('CLI: Stopping daemon', LOG_INFO);
        exec('kill ' . $pid);
        unlink($pidfile);
        
        echo "Stopped." . PHP_EOL;
        
    } elseif (in_array('status', $argv)) {
        
        // print the daemon status
        
        if (!file_exists($pidfile)) {
            echo "Not running" . PHP_EOL;
            exit();
        }
        $pid = file_get_contents($pidfile);
        exec('ps -p ' . $pid, $op);
        if (!isset($op[1])) echo "Not running" . PHP_EOL;
        else echo 'Running: ' . $pid . PHP_EOL;
        
    }
}

