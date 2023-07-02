<?php

use App\Fade;
use Dotenv\Dotenv;

require 'vendor/autoload.php';

$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->safeLoad();

$exit = (new Fade())->main();
exit($exit);
