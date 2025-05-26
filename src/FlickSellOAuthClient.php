<?php

namespace FlickSell;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * FlickSell OAuth Client SDK
 * 
 * Handles OAuth 2.0 authentication flow for FlickSell apps
 */
class FlickSellOAuthClient
{
    private $client_id;
    private $client_secret;
    private $flicksell_base_url;
    private $httpClient;
    private $access_token;
    private $refresh_token;
    private $token_expires_at;

    /**
     * Initialize FlickSell OAuth client
     * 
     * @param string $client_id Your app handle (client ID)
     * @param string $client_secret Your app's admin secret
     * @param string $flicksell_base_url Base URL of the FlickSell store
     */
    public function __construct($client_id, $client_secret, $flicksell_base_url)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->flicksell_base_url = rtrim($flicksell_base_url, '/');
        $this->httpClient = new HttpClient();
    }

    /**
     * Handle OAuth authorization callback
     * 
     * @param array $params GET parameters from OAuth callback
     * @return array|false Token response or false on failure
     */
    public function handleAuthorizationCallback($params)
    {
        if (!isset($params['code']) || !isset($params['client_id'])) {
            return false;
        }

        if ($params['client_id'] !== $this->client_id) {
            return false;
        }

        return $this->exchangeCodeForToken($params['code']);
    }

    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from FlickSell
     * @return array|false Token response or false on failure
     */
    public function exchangeCodeForToken($code)
    {
        try {
            $response = $this->httpClient->post($this->flicksell_base_url . '/admin/apps/oauth_token.php', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret
                ],
                'timeout' => 30
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['access_token'])) {
                $this->access_token = $data['access_token'];
                $this->refresh_token = $data['refresh_token'];
                $this->token_expires_at = time() + $data['expires_in'];
                
                return $data;
            }

            return false;

        } catch (RequestException $e) {
            error_log('OAuth token exchange failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh access token using refresh token
     * 
     * @return array|false New token response or false on failure
     */
    public function refreshAccessToken()
    {
        if (!$this->refresh_token) {
            return false;
        }

        try {
            $response = $this->httpClient->post($this->flicksell_base_url . '/admin/apps/oauth_token.php', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refresh_token,
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret
                ],
                'timeout' => 30
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['access_token'])) {
                $this->access_token = $data['access_token'];
                $this->refresh_token = $data['refresh_token'];
                $this->token_expires_at = time() + $data['expires_in'];
                
                return $data;
            }

            return false;

        } catch (RequestException $e) {
            error_log('OAuth token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Make authenticated API request to FlickSell
     * 
     * @param string $endpoint API endpoint (e.g., '/flicksell_storefront_api/get_users.php')
     * @param array $data Request data
     * @param string $method HTTP method
     * @return array Response data
     */
    public function makeApiRequest($endpoint, $data = [], $method = 'POST')
    {
        // Check if token needs refresh
        if ($this->token_expires_at && time() >= $this->token_expires_at - 60) {
            $this->refreshAccessToken();
        }

        if (!$this->access_token) {
            return [
                'success' => false,
                'error' => 'No valid access token available'
            ];
        }

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'User-Agent' => 'FlickSell-OAuth-SDK/1.0'
                ],
                'timeout' => 30
            ];

            if ($method === 'POST') {
                $options['form_params'] = $data;
            } elseif ($method === 'GET' && !empty($data)) {
                $endpoint .= '?' . http_build_query($data);
            }

            $response = $this->httpClient->request($method, $this->flicksell_base_url . $endpoint, $options);

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
     * Set tokens manually (e.g., from stored session/database)
     * 
     * @param string $access_token Access token
     * @param string $refresh_token Refresh token
     * @param int $expires_at Token expiration timestamp
     */
    public function setTokens($access_token, $refresh_token, $expires_at)
    {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
        $this->token_expires_at = $expires_at;
    }

    /**
     * Get current access token
     * 
     * @return string|null Access token
     */
    public function getAccessToken()
    {
        return $this->access_token;
    }

    /**
     * Get current refresh token
     * 
     * @return string|null Refresh token
     */
    public function getRefreshToken()
    {
        return $this->refresh_token;
    }

    /**
     * Get token expiration timestamp
     * 
     * @return int|null Expiration timestamp
     */
    public function getTokenExpiresAt()
    {
        return $this->token_expires_at;
    }

    /**
     * Check if access token is valid and not expired
     * 
     * @return bool True if token is valid
     */
    public function isTokenValid()
    {
        return $this->access_token && 
               $this->token_expires_at && 
               time() < $this->token_expires_at - 60; // 1 minute buffer
    }

    /**
     * Clear stored tokens
     */
    public function clearTokens()
    {
        $this->access_token = null;
        $this->refresh_token = null;
        $this->token_expires_at = null;
    }
} 