<?php

// Update comment

namespace FlickSell\Auth;

class AuthManager
{
    protected $sitename;
    private $storefrontKey;
    private $storefrontSecret;
    private $adminKey;
    private $adminSecret;

    public function createStorefrontAuth()
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $sitename = $this->sitename;
        $key = $this->storefrontKey;
        $secret = $this->storefrontSecret;

        $send = [
            "timestamp" => $timestamp,
            "nonce" => $nonce,
            "sitename" => $sitename,
            "apikey" => $key,
            "signature" => hash_hmac('sha256', $timestamp . " " . $nonce . " " . $sitename . " " . " ::::: Prototype 0 Registered", $secret)
        ];

        return base64_encode(json_encode($send));
    }

    public function makeStorefrontRequest($endpoint, $method = 'GET', $getParams = [], $postData = [])
    {
        $auth = $this->createStorefrontAuth();
        $url = "https://" . $this->sitename . ".flicksell.com/flicksell_storefront_api" . $endpoint;
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        return $this->executeRequest($url, $method, $auth, $postData);
    }

    public function makeAdminRequest($endpoint, $method = 'GET', $getParams = [], $postData = [])
    {
        $auth = $this->createAdminAuth();
        $url = "https://" . $this->sitename . ".flicksell.com/admin/api" . $endpoint;
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        return $this->executeRequest($url, $method, $auth, $postData);
    }

    private function createAdminAuth()
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $sitename = $this->sitename;
        $key = $this->adminKey;
        $secret = $this->adminSecret;

        $send = [
            "timestamp" => $timestamp,
            "nonce" => $nonce,
            "sitename" => $sitename,
            "apikey" => $key,
            "signature" => hash_hmac('sha256', $timestamp . " " . $nonce . " " . $sitename . " " . " ::::: Prototype 0 Registered", $secret)
        ];

        return base64_encode(json_encode($send));
    }

    private function executeRequest($url, $method, $auth, $postData = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-FlickSell-Auth: ' . $auth,
            'Content-Type: application/json'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if (!empty($postData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            }
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    public function setSitename($sitename)
    {
        $this->sitename = $sitename;
    }

    public function setStorefrontCredentials($key, $secret)
    {
        $this->storefrontKey = $key;
        $this->storefrontSecret = $secret;
    }

    public function setAdminCredentials($key, $secret)
    {
        $this->adminKey = $key;
        $this->adminSecret = $secret;
    }
}
