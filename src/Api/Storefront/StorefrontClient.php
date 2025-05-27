<?php

namespace FlickSell\Api\Storefront;

use FlickSell\Api\BaseClient;

class StorefrontClient extends BaseClient
{
    public function __construct($authManager) {
        parent::__construct($authManager);
    }

    public function getUsers() {
        return $this->authManager->makeStorefrontRequest('get-users');
    }
} 