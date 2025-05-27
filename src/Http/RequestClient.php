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
     * Send request to API endpoint (endpoint handles OAuth internally)
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
        // Get just the API key and secret - endpoint handles OAuth
        $credentials = $this->oauthManager->getCredentials($apiType);
        
        // Build URL
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $endpoint = ltrim($endpoint, '/');
        
        // Ensure .php extension for endpoints
        if (!str_contains($endpoint, '.php')) {
            $endpoint = str_replace('-', '_', $endpoint) . '.php';
        }
        
        $url = $baseUrl . '/' . $endpoint;
        
        if (!empty($getParams)) {
            $url .= '?' . http_build_query($getParams);
        }

        // Send API key/secret - let endpoint handle OAuth internally
        $allPostParams = array_merge([
            'api_key' => $credentials['key'],
            'api_secret' => $credentials['secret']
        ], $postParams);

        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $allPostParams,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: FlickSell-SDK/1.0'
            ],
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify'] ?? true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ]);

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
        $credentials = $this->oauthManager->getCredentials($apiType);
        
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $endpoint = ltrim($endpoint, '/');
        
        if (!str_contains($endpoint, '.php')) {
            $endpoint = str_replace('-', '_', $endpoint) . '.php';
        }
        
        $url = $baseUrl . '/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $allData = array_merge([
            'api_key' => $credentials['key'],
            'api_secret' => $credentials['secret']
        ], $data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $allData,
            CURLOPT_HTTPHEADER => [
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
        $credentials = $this->oauthManager->getCredentials($apiType);
        
        $baseUrl = $apiType === 'storefront' ? $this->storefrontBaseUrl : $this->adminBaseUrl;
        $endpoint = ltrim($endpoint, '/');
        
        if (!str_contains($endpoint, '.php')) {
            $endpoint = str_replace('-', '_', $endpoint) . '.php';
        }
        
        $url = $baseUrl . '/' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $deleteData = [
            'api_key' => $credentials['key'],
            'api_secret' => $credentials['secret']
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_POSTFIELDS => $deleteData,
            CURLOPT_HTTPHEADER => [
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


} 