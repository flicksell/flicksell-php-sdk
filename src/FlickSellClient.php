<?php

namespace FlickSell\Auth;

use FlickSell\Auth\OAuth\OAuthManager;
use FlickSell\Auth\Http\RequestClient;
use FlickSell\Auth\Cache\TokenCache;
use FlickSell\Auth\Exceptions\FlickSellException;

/**
 * Main FlickSell SDK Client
 * 
 * Handles dual OAuth authentication (storefront + admin) and API requests
 */
class FlickSellClient
{
    private $storefrontKey;
    private $storefrontSecret;
    private $adminKey;
    private $adminSecret;
    private $siteName;
    private $oauthManager;
    private $requestClient;
    private $tokenCache;

    /**
     * Initialize FlickSell Client
     *
     * @param string $siteName The store name (subdomain)
     * @param string|null $storefrontKey Storefront API key
     * @param string|null $storefrontSecret Storefront API secret
     * @param string|null $adminKey Admin API key
     * @param string|null $adminSecret Admin API secret
     * @param array $config Additional configuration options
     */
    public function __construct(
        string $siteName,
        ?string $storefrontKey = null,
        ?string $storefrontSecret = null,
        ?string $adminKey = null,
        ?string $adminSecret = null,
        array $config = []
    ) {
        $this->siteName = $siteName;
        $this->storefrontKey = $storefrontKey;
        $this->storefrontSecret = $storefrontSecret;
        $this->adminKey = $adminKey;
        $this->adminSecret = $adminSecret;

        // Initialize components
        $this->tokenCache = new TokenCache($config['redis'] ?? []);
        $this->oauthManager = new OAuthManager($siteName, $this->tokenCache, $config);
        $this->requestClient = new RequestClient($siteName, $this->oauthManager, $config);
    }

    /**
     * Authenticate with Storefront API
     * 
     * @throws FlickSellException
     */
    public function authenticateStorefront(): void
    {
        if (!$this->storefrontKey || !$this->storefrontSecret) {
            throw new FlickSellException('Storefront API key and secret are required');
        }

        $this->oauthManager->authenticate('storefront', $this->storefrontKey, $this->storefrontSecret);
    }

    /**
     * Authenticate with Admin API
     * 
     * @throws FlickSellException
     */
    public function authenticateAdmin(): void
    {
        if (!$this->adminKey || !$this->adminSecret) {
            throw new FlickSellException('Admin API key and secret are required');
        }

        $this->oauthManager->authenticate('admin', $this->adminKey, $this->adminSecret);
    }

    /**
     * Authenticate with both APIs (if credentials provided)
     * 
     * @throws FlickSellException
     */
    public function authenticate(): void
    {
        if ($this->storefrontKey && $this->storefrontSecret) {
            $this->authenticateStorefront();
        }

        if ($this->adminKey && $this->adminSecret) {
            $this->authenticateAdmin();
        }

        if (!$this->storefrontKey && !$this->adminKey) {
            throw new FlickSellException('At least one set of API credentials is required');
        }
    }

    /**
     * Send request to Storefront API
     *
     * @param string $endpoint API endpoint
     * @param array $getParams GET parameters
     * @param array $postParams POST parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function requestStorefront(string $endpoint, array $getParams = [], array $postParams = []): string
    {
        return $this->requestClient->request('storefront', $endpoint, $getParams, $postParams);
    }

    /**
     * Send request to Admin API
     *
     * @param string $endpoint API endpoint
     * @param array $getParams GET parameters
     * @param array $postParams POST parameters
     * @return string Raw response
     * @throws FlickSellException
     */
    public function requestAdmin(string $endpoint, array $getParams = [], array $postParams = []): string
    {
        return $this->requestClient->request('admin', $endpoint, $getParams, $postParams);
    }

    /**
     * Get store name
     */
    public function getSiteName(): string
    {
        return $this->siteName;
    }

    /**
     * Check if storefront is authenticated
     */
    public function isStorefrontAuthenticated(): bool
    {
        return $this->oauthManager->isAuthenticated('storefront');
    }

    /**
     * Check if admin is authenticated
     */
    public function isAdminAuthenticated(): bool
    {
        return $this->oauthManager->isAuthenticated('admin');
    }

    /**
     * Force refresh tokens for an API type
     *
     * @param string $apiType 'storefront' or 'admin'
     * @throws FlickSellException
     */
    public function refreshTokens(string $apiType): void
    {
        $this->oauthManager->refreshTokens($apiType);
    }
} 