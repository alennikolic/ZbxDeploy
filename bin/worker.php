<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ZbxDeploy\Worker\Worker;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The worker must be run from the command line.\n");
    exit(1);
}

(new Worker())->run();
