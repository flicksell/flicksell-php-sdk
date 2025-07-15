<?php

namespace FlickSell\Auth;

class AuthManager
{
    protected $sitename;
    private $storefrontKey;
    private $storefrontSecret;
    private $adminKey;
    private $adminSecret;
    private $userUuid = null;

    public function loginToFlicksell() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Store UUID from FlickSell header if available
        if (isset($_SERVER['HTTP_X_FLICKSELL_USER_UUID'])) {
            $this->userUuid = $_SERVER['HTTP_X_FLICKSELL_USER_UUID'];
            // Don't store "false" or "0" as valid UUIDs
            if ($this->userUuid === 'false' || $this->userUuid === '0') {
                $this->userUuid = null;
            }
        }

        // Check if already logged in
        if (isset($_SESSION['flicksell_auth_k7m9p2x8'])) {
            $sessionData = $_SESSION['flicksell_auth_k7m9p2x8'];
            $this->sitename = $sessionData['sitename'];
            return $sessionData;
        }

        // Not logged in, verify auth from request
        $req = $_REQUEST;
        $type = "api";

        if (isset($req['auth_key'])) {
            $req = base64_decode($req['auth_key']);
            $req = json_decode($req, true);
            if ($req['timestamp'] < time() - 300) {
                return ['success' => false, 'message' => 'Auth token expired'];
            }
            
            // Check admin credentials for login
            if ($this->adminKey == $req['apikey']) {
                $type = "admin";
                $secret = $this->adminSecret;
            } else if ($this->storefrontKey == $req['apikey']) {
                $type = "api";
                $secret = $this->storefrontSecret;
            } else {
                return ['success' => false, 'message' => 'Invalid API key'];
            }

            $signature = hash_hmac('sha256', $req['timestamp'] . " " . $req['nonce'] . " " . $req['sitename'] . " " . " ::::: Prototype 0 Registered", $secret);
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
        $nonce = $timestamp; // Use timestamp as nonce
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
        $url = "https://" . $this->sitename . ".flicksell.com/flicksell-storefront-api" . $endpoint;
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        // Always try to get the latest UUID from headers first
        $user_uuid = '0'; // Default fallback
        
        // Try to get from current request headers
        if (isset($_SERVER['HTTP_X_FLICKSELL_USER_UUID'])) {
            $header_uuid = $_SERVER['HTTP_X_FLICKSELL_USER_UUID'];
            if ($header_uuid && $header_uuid !== 'false' && $header_uuid !== '0') {
                $user_uuid = $header_uuid;
                // Update stored UUID
                $this->userUuid = $header_uuid;
            }
        }
        
        // If no valid UUID from headers, use stored UUID
        if ($user_uuid === '0' && $this->userUuid && $this->userUuid !== 'false' && $this->userUuid !== '0') {
            $user_uuid = $this->userUuid;
        }
        
        $additionalHeaders = [
            'X-FlickSell-User-UUID' => $user_uuid
        ];

        // Debug logging (remove in production)
        error_log("SDK makeStorefrontRequest - Endpoint: $endpoint, UUID being sent: $user_uuid, Stored UUID: " . ($this->userUuid ?: 'null') . ", Header UUID: " . ($_SERVER['HTTP_X_FLICKSELL_USER_UUID'] ?? 'not set'));

        return $this->executeRequest($url, $method, $auth, $postData, $additionalHeaders);
    }

    public function makeAdminRequest($endpoint, $method = 'GET', $getParams = [], $postData = [])
    {
        $auth = $this->createAdminAuth();
        $url = "https://" . $this->sitename . ".flicksell.com/admin/api" . $endpoint;
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        // Always try to get the latest UUID from headers first
        $user_uuid = '0'; // Default fallback
        
        // Try to get from current request headers
        if (isset($_SERVER['HTTP_X_FLICKSELL_USER_UUID'])) {
            $header_uuid = $_SERVER['HTTP_X_FLICKSELL_USER_UUID'];
            if ($header_uuid && $header_uuid !== 'false' && $header_uuid !== '0') {
                $user_uuid = $header_uuid;
                // Update stored UUID
                $this->userUuid = $header_uuid;
            }
        }
        
        // If no valid UUID from headers, use stored UUID
        if ($user_uuid === '0' && $this->userUuid && $this->userUuid !== 'false' && $this->userUuid !== '0') {
            $user_uuid = $this->userUuid;
        }
        
        $additionalHeaders = [
            'X-FlickSell-User-UUID' => $user_uuid
        ];

        return $this->executeRequest($url, $method, $auth, $postData, $additionalHeaders);
    }

    private function createAdminAuth()
    {
        $timestamp = time();
        $nonce = $timestamp; // Use timestamp as nonce
        $sitename = $this->sitename;
        $key = $this->storefrontKey; // Use storefront credentials for API requests
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

    private function executeRequest($url, $method, $auth, $postData = [], $additionalHeaders = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        // Build headers array - Use form data instead of JSON
        $headers = [
            'X-FlickSell-Auth: ' . $auth,
            'Content-Type: application/x-www-form-urlencoded'
        ];
        
        // Add additional headers (like UUID)
        foreach ($additionalHeaders as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if (!empty($postData)) {
                // Send as form data instead of JSON so $_POST gets populated
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
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

    public function getUserUuid()
    {
        return $this->userUuid;
    }

    public function setUserUuid($uuid)
    {
        $this->userUuid = $uuid;
    }
}
