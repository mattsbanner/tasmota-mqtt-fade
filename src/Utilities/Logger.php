<?php

namespace App\Utilities;

trait Logger
{
    /**
     * Output text to the console.
     *
     * @return void
     */
    private function log(string $message)
    {
        echo date('Y-m-d H:i:s').": $message\n";
    }
}
