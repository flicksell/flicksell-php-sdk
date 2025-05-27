<?php

namespace FlickSell\Api;

use FlickSell\Auth\AuthManager;

class BaseClient
{
    protected $authManager;

    public function __construct($authManager) {
        $this->authManager = $authManager;
    }

    public function dummy() {

    }
} 
