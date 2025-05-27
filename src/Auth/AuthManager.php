<?php

namespace FlickSell\Auth;

class AuthManager
{
    protected $sitename;
    private $storefrontKey;
    private $storefrontSecret;
    private $adminKey;
    private $adminSecret;

    public function loginToFlicksell() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check if already logged in
        if (isset($_SESSION['flicksell_auth_k7m9p2x8'])) {
            $sessionData = $_SESSION['flicksell_auth_k7m9p2x8'];
            $this->sitename = $sessionData['sitename'];
            return $sessionData;
        }

        // Not logged in, verify auth from request
        $req = $_REQUEST;
        $type = "storefront";

        if (isset($req['auth_key'])) {
            $req = base64_decode($req['auth_key']);
            $req = json_decode($req, true);
            if ($req['timestamp'] < time() - 300) {
                return ['success' => false, 'message' => 'Auth token expired'];
            }
            
            if ($this->adminKey == $req['apikey']) {
                $type = "admin";
            } else if ($this->storefrontKey == $req['apikey']) {
                $type = "storefront";
            } else {
                return ['success' => false, 'message' => 'Invalid API key'];
            }

            $signature = hash_hmac('sha256', $req['timestamp'] . " " . $req['nonce'] . " " . $req['sitename'] . " " . " ::::: Prototype 0 Registered", $type == "admin" ? $this->adminSecret : $this->storefrontSecret);
            if ($signature != $req['signature']) {
                return ['success' => false, 'message' => 'Invalid signature'];
            }

            // Auth successful, create session
            $_SESSION['flicksell_auth_k7m9p2x8'] = [
                "success" => true,
                "sitename" => $req['sitename'],
                "type" => $type
            ];
            $this->sitename = $req['sitename'];

            return ['success' => true, 'type' => $type];
        }

        return ['success' => false, 'message' => 'No auth data provided'];
    }

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
