<?php

namespace FlickSell\Auth\OAuth;

use FlickSell\Auth\Cache\TokenCache;
use FlickSell\Auth\Exceptions\FlickSellException;

/**
 * OAuth Manager for FlickSell APIs
 * 
 * Handles OAuth 2.0 flow for both storefront and admin APIs
 */
class OAuthManager
{
    private $siteName;
    private $tokenCache;
    private $config;
    private $baseUrl;

    /**
     * Initialize OAuth Manager
     *
     * @param string $siteName Store name
     * @param TokenCache $tokenCache Token cache instance
     * @param array $config Configuration options
     */
    public function __construct(string $siteName, TokenCache $tokenCache, array $config = [])
    {
        $this->siteName = $siteName;
        $this->tokenCache = $tokenCache;
        $this->config = $config;
        $this->baseUrl = $config['base_url'] ?? "https://{$siteName}.flicksell.com";
    }

    /**
     * Authenticate with FlickSell API
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $apiKey API key
     * @param string $apiSecret API secret
     * @throws FlickSellException
     */
    public function authenticate(string $apiType, string $apiKey, string $apiSecret): void
    {
        $appHandle = $this->getAppHandleFromKey($apiKey);
        
        // Check if we have valid cached tokens
        $tokens = $this->tokenCache->getTokens($appHandle, $apiType);
        if ($tokens && $this->isTokenValid($tokens)) {
            return; // Already authenticated with valid tokens
        }

        // Check if we can refresh existing tokens
        if ($tokens && isset($tokens['refresh_token'])) {
            try {
                $this->refreshTokensInternal($apiType, $appHandle, $tokens['refresh_token']);
                return;
            } catch (FlickSellException $e) {
                // Refresh failed, continue with new auth flow
            }
        }

        // Perform new OAuth flow
        $this->performOAuthFlow($apiType, $apiKey, $apiSecret, $appHandle);
    }

    /**
     * Check if user is authenticated for API type
     *
     * @param string $apiType 'storefront' or 'admin'
     * @return bool
     */
    public function isAuthenticated(string $apiType): bool
    {
        // We need to determine app handle somehow - for now return false if not cached
        // In practice, this would be called after authenticate() which sets up the tokens
        return false;
    }

    /**
     * Refresh tokens for API type
     *
     * @param string $apiType 'storefront' or 'admin'
     * @throws FlickSellException
     */
    public function refreshTokens(string $apiType): void
    {
        // Implementation would require storing app handle in instance
        throw new FlickSellException('Refresh tokens requires prior authentication');
    }

    /**
     * Get access token for API requests
     *
     * @param string $apiType 'storefront' or 'admin'
     * @param string $appHandle App handle
     * @return string Access token
     * @throws FlickSellException
     */
    public function getAccessToken(string $apiType, string $appHandle): string
    {
        $tokens = $this->tokenCache->getTokens($appHandle, $apiType);
        
        if (!$tokens) {
            throw new FlickSellException("No tokens found for {$apiType} API");
        }

        if (!$this->isTokenValid($tokens)) {
            // Try to refresh
            if (isset($tokens['refresh_token'])) {
                $this->refreshTokensInternal($apiType, $appHandle, $tokens['refresh_token']);
                $tokens = $this->tokenCache->getTokens($appHandle, $apiType);
            }
            
            if (!$tokens || !$this->isTokenValid($tokens)) {
                throw new FlickSellException("Invalid or expired tokens for {$apiType} API");
            }
        }

        return $tokens['access_token'];
    }

    /**
     * Perform OAuth 2.0 flow
     *
     * @param string $apiType API type
     * @param string $apiKey API key
     * @param string $apiSecret API secret
     * @param string $appHandle App handle
     * @throws FlickSellException
     */
    private function performOAuthFlow(string $apiType, string $apiKey, string $apiSecret, string $appHandle): void
    {
        // Generate OAuth parameters
        $nonce = $this->generateNonce();
        $timestamp = time();
        
        // Create OAuth request
        $oauthData = [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'api_type' => $apiType,
            'site_name' => $this->siteName,
            'nonce' => $nonce,
            'timestamp' => $timestamp
        ];

        // Send OAuth request to FlickSell
        $response = $this->sendOAuthRequest($oauthData);
        
        if (!$response || !isset($response['access_token'])) {
            throw new FlickSellException('OAuth authentication failed');
        }

        // Store tokens in cache
        $tokenData = [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? null,
            'expires_at' => $response['expires_at'] ?? (time() + 3600),
            'token_type' => $response['token_type'] ?? 'Bearer'
        ];

        $ttl = $tokenData['expires_at'] - time();
        $this->tokenCache->storeTokens($appHandle, $apiType, $tokenData, $ttl);
    }

    /**
     * Send OAuth request to FlickSell
     *
     * @param array $oauthData OAuth request data
     * @return array Response data
     * @throws FlickSellException
     */
    private function sendOAuthRequest(array $oauthData): array
    {
        $url = $this->baseUrl . '/oauth/token';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($oauthData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: FlickSell-SDK/1.0'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new FlickSellException("OAuth request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new FlickSellException("OAuth request failed with HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new FlickSellException('Invalid OAuth response format');
        }

        return $data;
    }

    /**
     * Refresh tokens internally
     *
     * @param string $apiType API type
     * @param string $appHandle App handle
     * @param string $refreshToken Refresh token
     * @throws FlickSellException
     */
    private function refreshTokensInternal(string $apiType, string $appHandle, string $refreshToken): void
    {
        $url = $this->baseUrl . '/oauth/refresh';
        
        $refreshData = [
            'refresh_token' => $refreshToken,
            'api_type' => $apiType,
            'app_handle' => $appHandle
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($refreshData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: FlickSell-SDK/1.0'
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new FlickSellException("Token refresh failed with HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            throw new FlickSellException('Token refresh failed');
        }

        // Update cached tokens
        $tokenData = [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => $data['expires_at'] ?? (time() + 3600),
            'token_type' => $data['token_type'] ?? 'Bearer'
        ];

        $ttl = $tokenData['expires_at'] - time();
        $this->tokenCache->storeTokens($appHandle, $apiType, $tokenData, $ttl);
    }

    /**
     * Check if token is valid
     *
     * @param array $tokens Token data
     * @return bool
     */
    private function isTokenValid(array $tokens): bool
    {
        if (!isset($tokens['access_token']) || !isset($tokens['expires_at'])) {
            return false;
        }

        // Check if token expires within next 60 seconds (buffer)
        return $tokens['expires_at'] > (time() + 60);
    }

    /**
     * Extract app handle from API key
     *
     * @param string $apiKey API key
     * @return string App handle
     */
    private function getAppHandleFromKey(string $apiKey): string
    {
        // Assume API key format includes app handle
        // This might need adjustment based on actual key format
        $parts = explode('_', $apiKey);
        return $parts[0] ?? $apiKey;
    }

    /**
     * Generate secure nonce
     *
     * @return string
     */
    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
} 