<?php

namespace FlickSell\Auth\OAuth;

use FlickSell\Auth\Cache\TokenCache;
use FlickSell\Auth\Exceptions\FlickSellException;

/**
 * Direct Auth Manager for FlickSell APIs
 * 
 * Handles direct key-based authentication for both storefront and admin APIs
 */
class OAuthManager
{
    private $siteName;
    private $tokenCache;
    private $config;
    private $baseUrl;
    private $credentials = [];

    /**
     * Initialize Auth Manager
     *
     * @param string $siteName Store name
     * @param TokenCache|null $tokenCache Token cache instance (not used for direct auth)
     * @param array $config Configuration options
     */
    public function __construct(string $siteName, ?TokenCache $tokenCache, array $config = [])
    {
        $this->siteName = $siteName;
        $this->tokenCache = $tokenCache;
        $this->config = $config;
        $this->baseUrl = $config['base_url'] ?? "https://{$siteName}.flicksell.com";
    }

    /**
     * Store credentials - endpoints handle OAuth internally
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $apiKey API key
     * @param string $apiSecret API secret
     * @throws FlickSellException
     */
    public function authenticate(string $apiType, string $apiKey, string $apiSecret): void
    {
        if (empty($apiKey) || empty($apiSecret)) {
            throw new FlickSellException("API key and secret are required for {$apiType}");
        }

        // Just store credentials - each endpoint handles OAuth internally
        $this->credentials[$apiType] = [
            'key' => $apiKey,
            'secret' => $apiSecret
        ];
    }

    /**
     * Check if user is authenticated for API type
     *
     * @param string $apiType 'storefront' or 'admin'
     * @return bool
     */
    public function isAuthenticated(string $apiType): bool
    {
        return isset($this->credentials[$apiType]);
    }

    /**
     * Refresh tokens for API type (not needed for direct auth)
     *
     * @param string $apiType 'storefront' or 'admin'
     * @throws FlickSellException
     */
    public function refreshTokens(string $apiType): void
    {
        // Not needed for direct authentication
        return;
    }

    /**
     * Get simple credentials for endpoint
     *
     * @param string $apiType 'storefront' or 'admin'
     * @return array Just API key and secret
     * @throws FlickSellException
     */
    public function getCredentials(string $apiType): array
    {
        if (!isset($this->credentials[$apiType])) {
            throw new FlickSellException("No credentials stored for {$apiType} API");
        }

        return $this->credentials[$apiType];
    }

} 