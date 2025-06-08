<?php

namespace FlickSell\Api\Storefront;

use FlickSell\Api\BaseClient;

class StorefrontClient extends BaseClient
{
    public function __construct($authManager) {
        parent::__construct($authManager);
    }

    public function getUsers() {
        return $this->authManager->makeStorefrontRequest('/get_users.php');
    }

    /**
     * Get checkout data for a user
     */
    public function getCheckoutData($userId, $coupon = null) {
        $data = ['user_id' => $userId];
        if ($coupon) {
            $data['coupon'] = $coupon;
        }
        return $this->authManager->makeStorefrontRequest('/checkout', 'POST', $data);
    }

    /**
     * Finalize an order
     */
    public function finalizeOrder($orderData) {
        return $this->authManager->makeStorefrontRequest('/finalize-order', 'POST', $orderData);
    }

    /**
     * General method to call any storefront API endpoint
     */
    public function callEndpoint($endpoint, $method = 'GET', $data = null, $headers = []) {
        // For GET requests, data goes to getParams; for POST requests, data goes to postData
        if ($method === 'GET') {
            return $this->authManager->makeStorefrontRequest($endpoint, $method, $data ?: [], []);
        } else {
            return $this->authManager->makeStorefrontRequest($endpoint, $method, [], $data ?: []);
        }
    }

    /**
     * Make a GET request to any endpoint
     */
    public function get($endpoint, $params = null, $headers = []) {
        $url = $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $this->authManager->makeStorefrontRequest($url, 'GET', null, $headers);
    }

    /**
     * Make a POST request to any endpoint
     */
    public function post($endpoint, $data = null, $headers = []) {
        return $this->authManager->makeStorefrontRequest($endpoint, 'POST', $data, $headers);
    }

    /**
     * Make a PUT request to any endpoint
     */
    public function put($endpoint, $data = null, $headers = []) {
        return $this->authManager->makeStorefrontRequest($endpoint, 'PUT', $data, $headers);
    }

    /**
     * Make a DELETE request to any endpoint
     */
    public function delete($endpoint, $data = null, $headers = []) {
        return $this->authManager->makeStorefrontRequest($endpoint, 'DELETE', $data, $headers);
    }
} 