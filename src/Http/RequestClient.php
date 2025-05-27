<?php

namespace FlickSell\Auth\Http;

use FlickSell\Auth\OAuth\OAuthManager;
use FlickSell\Auth\Exceptions\FlickSellException;

/**
 * HTTP Request Client for FlickSell APIs
 * 
 * Handles authenticated requests to storefront and admin APIs
 */
class RequestClient
{
    private $siteName;
    private $oauthManager;
    private $config;
    private $storefrontBaseUrl;
    private $adminBaseUrl;

    /**
     * Initialize Request Client
     *
     * @param string $siteName Store name
     * @param OAuthManager $oauthManager OAuth manager instance
     * @param array $config Configuration options
     */
    public function __construct(string $siteName, OAuthManager $oauthManager, array $config = [])
    {
        $this->siteName = $siteName;
        $this->oauthManager = $oauthManager;
        $this->config = $config;
        
        $this->storefrontBaseUrl = $config['storefront_base_url'] ?? "https://{$siteName}.flicksell.com/flicksell-storefront-api";
        $this->adminBaseUrl = $config['admin_base_url'] ?? "https://{$siteName}.flicksell.com/flicksell-admin-api";
    }

    /**
     * Send authenticated request to API
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $endpoint API endpoint
     * @param array $getParams GET parameters
     * @param array $postParams POST parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function request(string $apiType, string $endpoint, array $getParams = [], array $postParams = []): string
    {
        // Get app handle from cached data or derive from current session
        $appHandle = $this->getAppHandle($apiType);
        
        // Get access token
        $accessToken = $this->oauthManager->getAccessToken($apiType, $appHandle);
        
        // Build URL
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        // Prepare headers
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: FlickSell-SDK/1.0',
            'Accept: application/json'
        ];

        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify'] ?? true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);

        // Handle POST data
        if (!empty($postParams)) {
            curl_setopt($ch, CURLOPT_POST, true);
            
            // Determine content type
            if ($this->isFileUpload($postParams)) {
                // Multipart form data for file uploads
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);
            } else {
                // JSON data for regular requests
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postParams));
            }
        }

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Handle cURL errors
        if ($error) {
            throw new FlickSellException("Request failed: {$error}");
        }

        // Handle HTTP errors
        if ($httpCode >= 400) {
            $this->handleHttpError($httpCode, $response, $apiType, $endpoint);
        }

        return $response;
    }

    /**
     * Send GET request
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $endpoint API endpoint
     * @param array $params GET parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function get(string $apiType, string $endpoint, array $params = []): string
    {
        return $this->request($apiType, $endpoint, $params);
    }

    /**
     * Send POST request
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $endpoint API endpoint
     * @param array $data POST data
     * @param array $params GET parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function post(string $apiType, string $endpoint, array $data = [], array $params = []): string
    {
        return $this->request($apiType, $endpoint, $params, $data);
    }

    /**
     * Send PUT request
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $endpoint API endpoint
     * @param array $data PUT data
     * @param array $params GET parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function put(string $apiType, string $endpoint, array $data = [], array $params = []): string
    {
        $appHandle = $this->getAppHandle($apiType);
        $accessToken = $this->oauthManager->getAccessToken($apiType, $appHandle);
        
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'User-Agent: FlickSell-SDK/1.0'
            ],
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new FlickSellException("PUT request failed: {$error}");
        }

        if ($httpCode >= 400) {
            $this->handleHttpError($httpCode, $response, $apiType, $endpoint);
        }

        return $response;
    }

    /**
     * Send DELETE request
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $endpoint API endpoint
     * @param array $params GET parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function delete(string $apiType, string $endpoint, array $params = []): string
    {
        $appHandle = $this->getAppHandle($apiType);
        $accessToken = $this->oauthManager->getAccessToken($apiType, $appHandle);
        
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $url = $baseUrl . '/' . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'User-Agent: FlickSell-SDK/1.0'
            ],
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new FlickSellException("DELETE request failed: {$error}");
        }

        if ($httpCode >= 400) {
            $this->handleHttpError($httpCode, $response, $apiType, $endpoint);
        }

        return $response;
    }

    /**
     * Check if POST data contains file uploads
     *
     * @param array $data POST data
     * @return bool
     */
    private function isFileUpload(array $data): bool
    {
        foreach ($data as $value) {
            if ($value instanceof \CURLFile || (is_string($value) && strpos($value, '@') === 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle HTTP errors
     *
     * @param int $httpCode HTTP status code
     * @param string $response Response body
     * @param string $apiType API type
     * @param string $endpoint Endpoint
     * @throws FlickSellException
     */
    private function handleHttpError(int $httpCode, string $response, string $apiType, string $endpoint): void
    {
        $errorMessage = "HTTP {$httpCode} error on {$apiType} API endpoint '{$endpoint}'";
        
        // Try to extract error message from response
        $data = json_decode($response, true);
        if ($data && isset($data['error'])) {
            $errorMessage .= ": " . $data['error'];
        } elseif ($data && isset($data['message'])) {
            $errorMessage .= ": " . $data['message'];
        }

        throw new FlickSellException($errorMessage);
    }

    /**
     * Get app handle (placeholder - needs implementation based on your system)
     *
     * @param string $apiType API type
     * @return string App handle
     * @throws FlickSellException
     */
    private function getAppHandle(string $apiType): string
    {
        // This is a placeholder - in practice, you'd need to store the app handle
        // after authentication or derive it from the API key
        throw new FlickSellException('App handle resolution not implemented');
    }
} 