# FlickSell PHP SDK

Official PHP SDK for seamless integration with FlickSell stores. This SDK provides secure authentication and API communication between your apps and FlickSell stores.

## Features

- 🔐 **Secure HMAC-based authentication** with key + secret pairs
- 🚀 **Redis-powered nonce checking** (optional) to prevent replay attacks
- 🌐 **HTTP client** for making authenticated requests to FlickSell APIs
- ⏰ **Configurable timestamp validation** (up to 1 hour)
- 🛡️ **Built-in security best practices**
- 📱 **JWT-like token support** for iframe apps

## Installation

```bash
composer require flicksell/php-sdk
```

## Quick Start

### 1. App-Side Verification (Receiving requests from FlickSell)

```php
<?php
require_once 'vendor/autoload.php';

use FlickSell\FlickSellAuth;

// Initialize with your app's key and secret
$auth = new FlickSellAuth(
    'adk_your_admin_key_here',      // Your admin_key from FlickSell
    'your_admin_secret_here',       // Your admin_secret from FlickSell
    'YourStoreName'                 // Your store's sitename
);

// Verify incoming request from FlickSell
$payload = $auth->verifyRequest($_POST);

if ($payload) {
    echo "✅ Valid FlickSell request!";
    echo "Store: " . $payload['iss'];
    echo "Timestamp: " . $payload['iat'];
    
    // Store verification in session for subsequent requests
    session_start();
    $_SESSION['flicksell_verified'] = true;
    $_SESSION['flicksell_data'] = $payload;
} else {
    echo "❌ Invalid request";
    exit;
}
```

### 2. Making Authenticated Requests to FlickSell (App to Store)

```php
<?php
require_once 'vendor/autoload.php';

use FlickSell\FlickSellAuth;

// Initialize with your app's credentials
$auth = new FlickSellAuth(
    'adk_your_admin_key_here',
    'your_admin_secret_here',
    'YourStoreName'
);

// Send authenticated request to FlickSell API
$response = $auth->sendAuthenticatedRequest(
    'https://yourstore.flicksell.com/api/users',
    ['action' => 'get_users'],
    'POST'
);

if ($response['success']) {
    echo "API Response: " . $response['body'];
} else {
    echo "Error: " . $response['error'];
}
```

## Advanced Configuration

### With Redis (Recommended for Production)

```php
<?php
use FlickSell\FlickSellAuth;

// Redis configuration
$redisConfig = [
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
    'database' => 0
];

// Initialize with Redis for nonce checking
$auth = new FlickSellAuth(
    'adk_your_admin_key_here',
    'your_admin_secret_here',
    'YourStoreName',
    $redisConfig,
    300 // 5 minutes timestamp tolerance
);

// Now nonce checking is enabled automatically
$payload = $auth->verifyRequest();
```

### Using Storefront Keys

```php
<?php
// For storefront API access, use storefront credentials
$auth = new FlickSellAuth(
    'sfk_your_storefront_key_here',    // Storefront key
    'your_storefront_secret_here',     // Storefront secret
    'YourStoreName'
);
```

## API Reference

### FlickSellAuth Class

#### Constructor

```php
public function __construct($key, $secret, $sitename = 'Prototype0Registered', $redisConfig = null, $maxTimestampAge = 300)
```

- `$key` (string): Your app's API key (`admin_key` or `storefront_key`)
- `$secret` (string): Your app's secret (`admin_secret` or `storefront_secret`)
- `$sitename` (string): Store sitename for message signing
- `$redisConfig` (array|null): Redis configuration for nonce checking
- `$maxTimestampAge` (int): Maximum age of timestamps in seconds (max: 3600)

#### Methods

##### verifyToken($token)
Verify a FlickSell JWT-like token from iframe requests.

```php
$payload = $auth->verifyToken($_POST['flicksell_token']);
```

##### verifyRequest($requestData = null)
Convenience method to verify a request (uses $_REQUEST by default).

```php
$payload = $auth->verifyRequest($_POST);
```

##### sendAuthenticatedRequest($url, $data = [], $method = 'POST')
Send an authenticated request to FlickSell API.

```php
$response = $auth->sendAuthenticatedRequest(
    'https://store.flicksell.com/api/endpoint',
    ['param' => 'value'],
    'POST'
);
```

##### generateAuthParams()
Generate authentication parameters for manual API calls.

```php
$params = $auth->generateAuthParams();
// Returns: ['key' => '...', 'timestamp' => 123, 'nonce' => '...', 'signature' => '...']
```

##### generateToken($additionalData = [])
Generate a JWT-like token for iframe authentication.

```php
$token = $auth->generateToken(['custom' => 'data']);
```

## Authentication Flow

### FlickSell API Authentication
When making API calls to FlickSell, the SDK sends these parameters:

```php
[
    'key' => 'adk_...',           // Your API key
    'timestamp' => 1640995200,    // Current timestamp
    'nonce' => 'abc123...',       // Random nonce
    'signature' => 'def456...'    // HMAC signature
]
```

The signature is generated as:
```php
$message = "{$timestamp}_{$nonce}_{$sitename}";
$signature = hash_hmac('sha256', $message, $secret);
```

### Iframe Token Authentication
For iframe apps, FlickSell sends a JWT-like token:
```
base64(json_payload).hmac_signature
```

## Security Features

### Nonce Protection
- Prevents replay attacks by tracking used nonces
- Redis storage with automatic 1-hour expiration
- Graceful degradation if Redis unavailable

### Timestamp Validation
- Configurable time window (default: 5 minutes, max: 1 hour)
- Prevents old request replay
- Accounts for reasonable clock drift

### HMAC Signature
- Uses SHA-256 for cryptographic signatures
- Constant-time comparison to prevent timing attacks
- Matches FlickSell's `auth_app` function exactly

## Examples

### Complete App Integration

```php
<?php
session_start();
require_once 'vendor/autoload.php';

use FlickSell\FlickSellAuth;

$auth = new FlickSellAuth(
    'adk_your_key_here',
    'your_secret_here',
    'YourStoreName'
);

// Handle initial FlickSell request
if (isset($_POST['flicksell_token']) && !isset($_SESSION['flicksell_verified'])) {
    $payload = $auth->verifyRequest();
    
    if ($payload) {
        $_SESSION['flicksell_verified'] = true;
        $_SESSION['store_name'] = $payload['iss'];
        $_SESSION['verified_at'] = time();
    } else {
        die('Unauthorized');
    }
}

// Check if session is still valid
if (!isset($_SESSION['flicksell_verified']) || 
    (time() - $_SESSION['verified_at']) > 3600) {
    die('Session expired');
}

// Your app logic here
echo "Welcome to the app for store: " . $_SESSION['store_name'];

// Make API calls when needed
if (isset($_POST['get_users'])) {
    $response = $auth->sendAuthenticatedRequest(
        'https://yourstore.flicksell.com/api/users'
    );
    
    if ($response['success']) {
        $users = json_decode($response['body'], true);
        // Process users...
    }
}
?>
```

### Manual API Call

```php
<?php
// Generate auth parameters manually
$params = $auth->generateAuthParams();

// Use with cURL or any HTTP client
$postData = array_merge($params, [
    'action' => 'get_products',
    'limit' => 10
]);

$ch = curl_init('https://yourstore.flicksell.com/api/products');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
?>
```

## Requirements

- PHP 7.4 or higher
- Redis (optional, for nonce checking)
- Composer

## License

MIT License - see LICENSE file for details.

## Support

For issues and questions:
- GitHub Issues: [flicksell/php-sdk](https://github.com/flicksell/php-sdk)
- Email: dev@flicksell.com
- Documentation: [docs.flicksell.com](https://docs.flicksell.com) 