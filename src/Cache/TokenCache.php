<?php

namespace FlickSell\Auth\Cache;

use Predis\Client as RedisClient;

/**
 * Token Cache using Redis
 * 
 * Manages OAuth token storage and retrieval with automatic expiration
 */
class TokenCache
{
    private $redis;
    private $prefix = 'flicksell_oauth:';

    /**
     * Initialize Token Cache
     *
     * @param array $redisConfig Redis configuration
     */
    public function __construct(array $redisConfig = [])
    {
        $defaultConfig = [
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0
        ];

        $config = array_merge($defaultConfig, $redisConfig);
        $this->redis = new RedisClient($config);
        
        if (isset($redisConfig['prefix'])) {
            $this->prefix = $redisConfig['prefix'];
        }
    }

    /**
     * Store OAuth tokens
     *
     * @param string $appHandle App handle
     * @param string $apiType 'storefront' or 'admin'
     * @param array $tokens Token data
     * @param int $ttl Time to live in seconds
     */
    public function storeTokens(string $appHandle, string $apiType, array $tokens, int $ttl = 3600): void
    {
        $key = $this->getTokenKey($appHandle, $apiType);
        $this->redis->setex($key, $ttl, json_encode($tokens));
    }

    /**
     * Get OAuth tokens
     *
     * @param string $appHandle App handle
     * @param string $apiType 'storefront' or 'admin'
     * @return array|null Token data or null if not found
     */
    public function getTokens(string $appHandle, string $apiType): ?array
    {
        $key = $this->getTokenKey($appHandle, $apiType);
        $data = $this->redis->get($key);
        
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Delete OAuth tokens
     *
     * @param string $appHandle App handle
     * @param string $apiType 'storefront' or 'admin'
     */
    public function deleteTokens(string $appHandle, string $apiType): void
    {
        $key = $this->getTokenKey($appHandle, $apiType);
        $this->redis->del($key);
    }

    /**
     * Store session data
     *
     * @param string $sessionId Session ID
     * @param array $data Session data
     * @param int $ttl Time to live in seconds
     */
    public function storeSession(string $sessionId, array $data, int $ttl = 300): void
    {
        $key = $this->prefix . 'session:' . $sessionId;
        $this->redis->setex($key, $ttl, json_encode($data));
    }

    /**
     * Get session data
     *
     * @param string $sessionId Session ID
     * @return array|null Session data or null if not found
     */
    public function getSession(string $sessionId): ?array
    {
        $key = $this->prefix . 'session:' . $sessionId;
        $data = $this->redis->get($key);
        
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Delete session data
     *
     * @param string $sessionId Session ID
     */
    public function deleteSession(string $sessionId): void
    {
        $key = $this->prefix . 'session:' . $sessionId;
        $this->redis->del($key);
    }

    /**
     * Generate token cache key
     *
     * @param string $appHandle App handle
     * @param string $apiType API type
     * @return string Cache key
     */
    private function getTokenKey(string $appHandle, string $apiType): string
    {
        return $this->prefix . 'tokens:' . $appHandle . ':' . $apiType;
    }

    /**
     * Check if cache is connected
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            $this->redis->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
} 