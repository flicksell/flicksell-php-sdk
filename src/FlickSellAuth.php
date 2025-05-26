<?php

namespace FlickSell;

use Predis\Client as RedisClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * FlickSell Authentication SDK
 * 
 * Handles secure authentication between Flicksell stores and apps
 */
class FlickSellAuth
{
    private $key;
    private $secret;
    private $redis;
    private $httpClient;
    private $maxTimestampAge;
    private $useRedis;
    private $sitename;

    /**
     * Initialize FlickSell authentication
     * 
     * @param string $key Your app's API key (admin_key or storefront_key)
     * @param string $secret Your app's secret (admin_secret or storefront_secret)
     * @param string $siteId FlickSell site ID for message signing
     * @param array $redisConfig Redis configuration (optional)
     * @param int $maxTimestampAge Maximum age for timestamps in seconds (default: 300 = 5 minutes)
     */
    public function __construct($key, $secret, $siteId = 'Prototype0Registered', $redisConfig = null, $maxTimestampAge = 300)
    {
        $this->key = $key;
        $this->secret = $secret;
        $this->sitename = $siteId; // Store as sitename for backward compatibility
        $this->maxTimestampAge = $maxTimestampAge;
        $this->httpClient = new HttpClient();

        // Initialize Redis if config provided
        if ($redisConfig) {
            try {
                $this->redis = new RedisClient($redisConfig);
                $this->useRedis = true;
                // Test connection
                $this->redis->ping();
            } catch (\Exception $e) {
                error_log("FlickSell SDK: Redis connection failed: " . $e->getMessage());
                $this->useRedis = false;
            }
        } else {
            $this->useRedis = false;
        }
    }

    /**
     * Verify a Flicksell token (JWT-like format from iframe)
     * 
     * @param string $token The flicksell_token from POST/GET data
     * @return array|false Returns decoded payload on success, false on failure
     */
    public function verifyToken($token)
    {
        if (empty($token)) {
            return false;
        }

        // Split token into payload and signature
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        list($payloadBase64, $receivedSignature) = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payloadBase64, $this->secret);
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            return false;
        }

        // Decode payload
        $payload = json_decode(base64_decode($payloadBase64), true);
        if (!$payload) {
            return false;
        }

        // Verify required fields
        if (!isset($payload['iat']) || !isset($payload['nonce'])) {
            return false;
        }

        // Check timestamp freshness
        if (abs(time() - $payload['iat']) > $this->maxTimestampAge) {
            return false;
        }

        // Check nonce (if Redis is available)
        if ($this->useRedis && !$this->checkAndStoreNonce($payload['nonce'], $payload['iat'])) {
            return false;
        }

        return $payload;
    }

    /**
     * Generate and send an authenticated request to Flicksell API
     * 
     * @param string $url The Flicksell API endpoint URL
     * @param array $data Additional data to send (optional)
     * @param string $method HTTP method (POST, GET, etc.)
     * @return array Response data
     */
    public function sendAuthenticatedRequest($url, $data = [], $method = 'POST')
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        
        // Create message in FlickSell format: {timestamp}_{nonce}_{sitename}
        $message = "{$timestamp}_{$nonce}_{$this->sitename}";
        $signature = hash_hmac('sha256', $message, $this->secret);
        
        $requestData = array_merge($data, [
            'key' => $this->key,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature
        ]);

        try {
            $response = $this->httpClient->request($method, $url, [
                'form_params' => $requestData,
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'FlickSell-SDK/1.0'
                ]
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'body' => $response->getBody()->getContents(),
                'headers' => $response->getHeaders()
            ];

        } catch (RequestException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
                'body' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null
            ];
        }
    }

    /**
     * Generate a token for authenticating with Flicksell (JWT-like format)
     * 
     * @param array $additionalData Additional payload data
     * @return string The generated token
     */
    public function generateToken($additionalData = [])
    {
        $payload = array_merge([
            'iss' => $this->sitename,
            'iat' => time(),
            'nonce' => bin2hex(random_bytes(16)),
            'sdk_version' => '1.0'
        ], $additionalData);

        $payloadBase64 = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $payloadBase64, $this->secret);

        return $payloadBase64 . '.' . $signature;
    }

    /**
     * Generate authentication parameters for manual API calls
     * 
     * @return array Array with key, timestamp, nonce, and signature
     */
    public function generateAuthParams()
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $message = "{$timestamp}_{$nonce}_{$this->sitename}";
        $signature = hash_hmac('sha256', $message, $this->secret);
        
        return [
            'key' => $this->key,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature
        ];
    }

    /**
     * Check if nonce has been used and store it
     * 
     * @param string $nonce The nonce to check
     * @param int $timestamp The timestamp from the request
     * @return bool True if nonce is valid (not used), false if already used
     */
    private function checkAndStoreNonce($nonce, $timestamp)
    {
        if (!$this->useRedis) {
            return true; // Skip nonce checking if Redis not available
        }

        $key = "flicksell:nonce:" . $this->key . ":" . $nonce;
        
        // Check if nonce already exists
        if ($this->redis->exists($key)) {
            return false; // Nonce already used
        }

        // Store nonce with TTL of 1 hour
        $this->redis->setex($key, 3600, $timestamp);
        
        return true;
    }

    /**
     * Verify a request from Flicksell (convenience method)
     * 
     * @param array $requestData $_POST or $_GET data
     * @return array|false Returns payload on success, false on failure
     */
    public function verifyRequest($requestData = null)
    {
        if ($requestData === null) {
            $requestData = $_REQUEST;
        }

        if (!isset($requestData['flicksell_token'])) {
            return false;
        }

        return $this->verifyToken($requestData['flicksell_token']);
    }

    /**
     * Get the maximum allowed timestamp age
     * 
     * @return int Maximum timestamp age in seconds
     */
    public function getMaxTimestampAge()
    {
        return $this->maxTimestampAge;
    }

    /**
     * Check if Redis is available for nonce checking
     * 
     * @return bool True if Redis is available
     */
    public function isRedisAvailable()
    {
        return $this->useRedis;
    }

    /**
     * Get the API key
     * 
     * @return string The API key
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Get the sitename used for message signing
     * 
     * @return string The sitename
     */
    public function getSitename()
    {
        return $this->sitename;
    }
} 