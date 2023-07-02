<?php

namespace App\Entities;

class ProxyTopic
{
    public function __construct(
        public string $original,
        public string $proxy
    ) {
    }
}
