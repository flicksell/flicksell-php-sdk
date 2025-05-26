<?php

namespace FlickSell;

use Predis\Client as RedisClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * FlickSell Authentication SDK
 * 
 * Handles secure authentication between Flicksell stores and apps
 * Supports both legacy API key authentication and OAuth 2.0 flow
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
    private $useSession;
    private $sessionPrefix;
    
    // OAuth 2.0 properties
    private $client_id;
    private $client_secret;
    private $flicksell_base_url;
    private $access_token;
    private $refresh_token;
    private $token_expires_at;
    private $oauth_mode;

    /**
     * Initialize FlickSell authentication
     * 
     * @param string $key Your app's API key (admin_key or storefront_key) OR client_id for OAuth
     * @param string $secret Your app's secret (admin_secret or storefront_secret) OR client_secret for OAuth
     * @param string $siteId FlickSell site ID for message signing OR base URL for OAuth
     * @param array $redisConfig Redis configuration (optional)
     * @param int $maxTimestampAge Maximum age for timestamps in seconds (default: 300 = 5 minutes)
     * @param bool $useSession Whether to use session management (default: true)
     * @param bool $oauthMode Whether to use OAuth 2.0 mode (default: false)
     */
    public function __construct($key, $secret, $siteId = 'Prototype0Registered', $redisConfig = null, $maxTimestampAge = 300, $useSession = true, $oauthMode = false)
    {
        $this->oauth_mode = $oauthMode;
        
        if ($this->oauth_mode) {
            // OAuth 2.0 mode
            $this->client_id = $key;
            $this->client_secret = $secret;
            $this->flicksell_base_url = rtrim($siteId, '/');
        } else {
            // Legacy API key mode
            $this->key = $key;
            $this->secret = $secret;
            $this->sitename = $siteId;
        }
        
        $this->maxTimestampAge = $maxTimestampAge;
        $this->httpClient = new HttpClient();
        $this->useSession = $useSession;
        $this->sessionPrefix = 'flicksell_auth_' . substr(md5($key), 0, 8);

        // Start session if using session management and not already started
        if ($this->useSession && session_status() === PHP_SESSION_NONE) {
            session_start();
        }

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
     * Generate OAuth 2.0 authorization URL
     * 
     * @param string $redirect_uri Your app's callback URL
     * @param array $scopes Array of permission scopes
     * @param string $state Optional state parameter for CSRF protection
     * @return string Authorization URL
     */
    public function getAuthorizationUrl($redirect_uri, $scopes = [], $state = null)
    {
        if (!$this->oauth_mode) {
            throw new \Exception('OAuth mode must be enabled to use this method');
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'scope' => implode(' ', $scopes)
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return $this->flicksell_base_url . '/admin/apps/oauth_authorize.php?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from FlickSell
     * @param string $redirect_uri The same redirect URI used in authorization
     * @return array|false Token response or false on failure
     */
    public function exchangeCodeForToken($code, $redirect_uri)
    {
        if (!$this->oauth_mode) {
            throw new \Exception('OAuth mode must be enabled to use this method');
        }

        try {
            $response = $this->httpClient->post($this->flicksell_base_url . '/admin/apps/oauth_token.php', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'redirect_uri' => $redirect_uri
                ],
                'timeout' => 30
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['access_token'])) {
                $this->access_token = $data['access_token'];
                $this->refresh_token = $data['refresh_token'];
                $this->token_expires_at = time() + $data['expires_in'];
                
                // Store in session if enabled
                if ($this->useSession) {
                    $this->storeOAuthTokens($data);
                }
                
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
        if (!$this->oauth_mode) {
            throw new \Exception('OAuth mode must be enabled to use this method');
        }

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
                
                // Store in session if enabled
                if ($this->useSession) {
                    $this->storeOAuthTokens($data);
                }
                
                return $data;
            }

            return false;

        } catch (RequestException $e) {
            error_log('OAuth token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set OAuth tokens manually (e.g., from stored session/database)
     * 
     * @param string $access_token Access token
     * @param string $refresh_token Refresh token
     * @param int $expires_at Token expiration timestamp
     */
    public function setOAuthTokens($access_token, $refresh_token, $expires_at)
    {
        if (!$this->oauth_mode) {
            throw new \Exception('OAuth mode must be enabled to use this method');
        }

        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
        $this->token_expires_at = $expires_at;
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
        $secret = $this->oauth_mode ? $this->client_secret : $this->secret;
        $expectedSignature = hash_hmac('sha256', $payloadBase64, $secret);
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
        if ($this->oauth_mode) {
            return $this->makeOAuthRequest($url, $data, $method);
        }

        // Legacy API key authentication
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
                    'User-Agent' => 'FlickSell-SDK/2.0'
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
     * Make OAuth authenticated API request
     * 
     * @param string $url API endpoint URL
     * @param array $data Request data
     * @param string $method HTTP method
     * @return array Response data
     */
    private function makeOAuthRequest($url, $data = [], $method = 'POST')
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
                    'User-Agent' => 'FlickSell-OAuth-SDK/2.0'
                ],
                'timeout' => 30
            ];

            if ($method === 'POST') {
                $options['form_params'] = $data;
            } elseif ($method === 'GET' && !empty($data)) {
                $url .= '?' . http_build_query($data);
            }

            $response = $this->httpClient->request($method, $url, $options);

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
        $sitename = $this->oauth_mode ? $this->flicksell_base_url : $this->sitename;
        
        $payload = array_merge([
            'iss' => $sitename,
            'iat' => time(),
            'nonce' => bin2hex(random_bytes(16)),
            'sdk_version' => '2.0'
        ], $additionalData);

        $payloadBase64 = base64_encode(json_encode($payload));
        $secret = $this->oauth_mode ? $this->client_secret : $this->secret;
        $signature = hash_hmac('sha256', $payloadBase64, $secret);

        return $payloadBase64 . '.' . $signature;
    }

    /**
     * Generate authentication parameters for manual API calls
     * 
     * @return array Array with key, timestamp, nonce, and signature
     */
    public function generateAuthParams()
    {
        if ($this->oauth_mode) {
            throw new \Exception('Use OAuth tokens for authentication in OAuth mode');
        }

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
            return true; // Skip nonce checking if Redis is not available
        }

        try {
            $key = "flicksell_nonce:" . $nonce;
            
            // Check if nonce already exists
            if ($this->redis->exists($key)) {
                return false;
            }
            
            // Store nonce with expiration
            $this->redis->setex($key, $this->maxTimestampAge * 2, $timestamp);
            return true;
            
        } catch (\Exception $e) {
            error_log("FlickSell SDK: Redis nonce check failed: " . $e->getMessage());
            return true; // Allow request if Redis fails
        }
    }

    /**
     * Verify request data (handles both token and direct request verification)
     * 
     * @param array $requestData Request data (POST/GET)
     * @return array|false Returns payload on success, false on failure
     */
    public function verifyRequest($requestData = null)
    {
        if ($requestData === null) {
            $requestData = array_merge($_GET, $_POST);
        }

        // Check for flicksell_token first
        if (isset($requestData['flicksell_token'])) {
            $payload = $this->verifyToken($requestData['flicksell_token']);
            if ($payload && $this->useSession) {
                $this->storeAuthSession($payload);
            }
            return $payload;
        }

        // Check for OAuth access token in Authorization header
        if ($this->oauth_mode) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                    $token = $matches[1];
                    // Verify token with FlickSell API
                    return $this->verifyOAuthToken($token);
                }
            }
        }

        return false;
    }

    /**
     * Verify OAuth access token with FlickSell API
     * 
     * @param string $token Access token
     * @return array|false Token info or false on failure
     */
    private function verifyOAuthToken($token)
    {
        try {
            $response = $this->httpClient->post($this->flicksell_base_url . '/admin/apps/oauth_verify.php', [
                'form_params' => [
                    'access_token' => $token,
                    'client_id' => $this->client_id
                ],
                'timeout' => 30
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['valid'] ? $data : false;

        } catch (RequestException $e) {
            error_log('OAuth token verification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user is authenticated
     * 
     * @return bool True if authenticated, false otherwise
     */
    public function isAuthenticated()
    {
        if ($this->oauth_mode) {
            return $this->access_token && time() < $this->token_expires_at;
        }

        if (!$this->useSession) {
            return false;
        }

        $authData = $this->getStoredAuthData();
        if (!$authData) {
            return false;
        }

        // Check if stored auth is still valid
        if (isset($authData['iat']) && (time() - $authData['iat']) > $this->maxTimestampAge * 2) {
            $this->clearAuthSession();
            return false;
        }

        return true;
    }

    /**
     * Store OAuth tokens in session
     * 
     * @param array $tokenData Token response data
     */
    private function storeOAuthTokens($tokenData)
    {
        if (!$this->useSession) {
            return;
        }

        $_SESSION[$this->sessionPrefix . '_oauth'] = [
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'expires_at' => time() + $tokenData['expires_in'],
            'stored_at' => time()
        ];
    }

    /**
     * Get stored OAuth tokens from session
     * 
     * @return array|false Token data or false if not found
     */
    private function getStoredOAuthTokens()
    {
        if (!$this->useSession) {
            return false;
        }

        $key = $this->sessionPrefix . '_oauth';
        return isset($_SESSION[$key]) ? $_SESSION[$key] : false;
    }

    /**
     * Store authentication session data
     * 
     * @param array $payload Verified token payload
     */
    private function storeAuthSession($payload)
    {
        if (!$this->useSession) {
            return;
        }

        $_SESSION[$this->sessionPrefix] = [
            'payload' => $payload,
            'stored_at' => time()
        ];
    }

    /**
     * Get stored authentication data
     * 
     * @return array|false Stored auth data or false if not found
     */
    private function getStoredAuthData()
    {
        if (!$this->useSession) {
            return false;
        }

        $key = $this->sessionPrefix;
        return isset($_SESSION[$key]) ? $_SESSION[$key]['payload'] : false;
    }

    /**
     * Clear authentication session
     */
    public function clearAuthSession()
    {
        if (!$this->useSession) {
            return;
        }

        unset($_SESSION[$this->sessionPrefix]);
        unset($_SESSION[$this->sessionPrefix . '_oauth']);
    }

    /**
     * Get authenticated site ID
     * 
     * @return string|false Site ID or false if not authenticated
     */
    public function getAuthenticatedSiteId()
    {
        if ($this->oauth_mode) {
            // In OAuth mode, site ID should be extracted from token verification
            return $this->flicksell_base_url;
        }

        $authData = $this->getStoredAuthData();
        return $authData ? ($authData['iss'] ?? false) : false;
    }

    /**
     * Get maximum timestamp age
     * 
     * @return int Maximum timestamp age in seconds
     */
    public function getMaxTimestampAge()
    {
        return $this->maxTimestampAge;
    }

    /**
     * Check if Redis is available
     * 
     * @return bool True if Redis is available, false otherwise
     */
    public function isRedisAvailable()
    {
        return $this->useRedis;
    }

    /**
     * Get API key (legacy mode only)
     * 
     * @return string|false API key or false in OAuth mode
     */
    public function getKey()
    {
        return $this->oauth_mode ? false : $this->key;
    }

    /**
     * Get client ID (OAuth mode) or sitename (legacy mode)
     * 
     * @return string Client ID or sitename
     */
    public function getSitename()
    {
        return $this->oauth_mode ? $this->client_id : $this->sitename;
    }

    /**
     * Enable or disable session management
     * 
     * @param bool $useSession Whether to use session management
     */
    public function setSessionManagement($useSession)
    {
        $this->useSession = $useSession;
        
        if ($this->useSession && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Check if session management is enabled
     * 
     * @return bool True if session management is enabled
     */
    public function isSessionManagementEnabled()
    {
        return $this->useSession;
    }

    /**
     * Verify token without storing session data (stateless)
     * 
     * @param string $token The flicksell_token
     * @return array|false Returns decoded payload on success, false on failure
     */
    public function verifyTokenStateless($token)
    {
        $originalUseSession = $this->useSession;
        $this->useSession = false;
        
        $result = $this->verifyToken($token);
        
        $this->useSession = $originalUseSession;
        return $result;
    }

    /**
     * Create a new instance with session management disabled
     * 
     * @return FlickSellAuth New stateless instance
     */
    public function createStatelessInstance()
    {
        if ($this->oauth_mode) {
            $instance = new self($this->client_id, $this->client_secret, $this->flicksell_base_url, null, $this->maxTimestampAge, false, true);
            $instance->setOAuthTokens($this->access_token, $this->refresh_token, $this->token_expires_at);
        } else {
            $instance = new self($this->key, $this->secret, $this->sitename, null, $this->maxTimestampAge, false, false);
        }
        
        return $instance;
    }

    /**
     * Check if OAuth mode is enabled
     * 
     * @return bool True if OAuth mode is enabled
     */
    public function isOAuthMode()
    {
        return $this->oauth_mode;
    }

    /**
     * Get access token (OAuth mode only)
     * 
     * @return string|false Access token or false if not available
     */
    public function getAccessToken()
    {
        return $this->oauth_mode ? $this->access_token : false;
    }

    /**
     * Get refresh token (OAuth mode only)
     * 
     * @return string|false Refresh token or false if not available
     */
    public function getRefreshToken()
    {
        return $this->oauth_mode ? $this->refresh_token : false;
    }

    /**
     * Get token expiration timestamp (OAuth mode only)
     * 
     * @return int|false Token expiration timestamp or false if not available
     */
    public function getTokenExpiresAt()
    {
        return $this->oauth_mode ? $this->token_expires_at : false;
    }

    /**
     * Check if OAuth token is valid (OAuth mode only)
     * 
     * @return bool True if token is valid
     */
    public function isTokenValid()
    {
        if (!$this->oauth_mode) {
            return false;
        }

        return $this->access_token && time() < $this->token_expires_at;
    }

    /**
     * Clear OAuth tokens (OAuth mode only)
     */
    public function clearOAuthTokens()
    {
        if ($this->oauth_mode) {
            $this->access_token = null;
            $this->refresh_token = null;
            $this->token_expires_at = null;
            
            if ($this->useSession) {
                unset($_SESSION[$this->sessionPrefix . '_oauth']);
            }
        }
    }
} 