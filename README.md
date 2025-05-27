# FlickSell Auth SDK

Official PHP SDK for FlickSell API authentication and requests. Supports dual OAuth authentication for both Storefront and Admin APIs with automatic token management, caching, and refresh.

## Features

- 🔐 **Dual OAuth Support** - Separate authentication for Storefront and Admin APIs
- 🚀 **Automatic Token Management** - Handles token refresh and caching automatically
- 📦 **Redis Caching** - Built-in Redis support for token storage
- 🔄 **Smart Authentication** - Only authenticates when needed, reuses valid tokens
- 🌐 **Full HTTP Support** - GET, POST, PUT, DELETE requests with proper error handling
- 🛡️ **Security First** - Nonce generation, timestamp validation, secure token storage

## Installation

```bash
composer require flicksell/auth-sdk
```

## Quick Start

```php
<?php

use FlickSell\Auth\FlickSellClient;
use FlickSell\Auth\Exceptions\FlickSellException;

// Initialize client with your credentials
$client = new FlickSellClient(
    siteName: 'mystore',
    storefrontKey: 'myapp_sf_key_12345',
    storefrontSecret: 'myapp_sf_secret_abcdef',
    adminKey: 'myapp_adm_key_67890',  // Optional
    adminSecret: 'myapp_adm_secret_ghijkl'  // Optional
);

// Authenticate (handles everything automatically)
$client->authenticate();

// Make API requests
$products = $client->requestStorefront('products', ['limit' => 10]);
$orders = $client->requestAdmin('orders', ['status' => 'pending']);
```

## Authentication

The SDK supports three authentication modes:

### 1. Storefront Only
```php
$client = new FlickSellClient(
    siteName: 'mystore',
    storefrontKey: 'your_storefront_key',
    storefrontSecret: 'your_storefront_secret'
);

$client->authenticateStorefront();
```

### 2. Admin Only
```php
$client = new FlickSellClient(
    siteName: 'mystore',
    adminKey: 'your_admin_key',
    adminSecret: 'your_admin_secret'
);

$client->authenticateAdmin();
```

### 3. Both APIs
```php
$client = new FlickSellClient(
    siteName: 'mystore',
    storefrontKey: 'your_storefront_key',
    storefrontSecret: 'your_storefront_secret',
    adminKey: 'your_admin_key',
    adminSecret: 'your_admin_secret'
);

$client->authenticate(); // Authenticates both
```

## Making API Requests

### Storefront API Requests

Requests are sent to: `https://{sitename}.flicksell.com/flicksell-storefront-api/{endpoint}`

```php
// GET request with query parameters
$products = $client->requestStorefront('products', ['limit' => 10, 'page' => 1]);

// POST request with data
$cart = $client->requestStorefront('cart/add', [], [
    'product_id' => 123,
    'quantity' => 2,
    'variant_id' => 456
]);

// Simple GET
$product = $client->requestStorefront('products/123');
```

### Admin API Requests

Requests are sent to: `https://{sitename}.flicksell.com/flicksell-admin-api/{endpoint}`

```php
// GET orders with filters
$orders = $client->requestAdmin('orders', [
    'status' => 'pending',
    'created_after' => '2024-01-01'
]);

// Create new product
$newProduct = $client->requestAdmin('products', [], [
    'name' => 'New Product',
    'price' => 29.99,
    'description' => 'Product description',
    'inventory_quantity' => 100
]);

// Update existing product
$updatedProduct = $client->requestAdmin('products/123', [], [
    'price' => 39.99,
    'inventory_quantity' => 150
]);
```

## Configuration Options

```php
$client = new FlickSellClient(
    siteName: 'mystore',
    storefrontKey: 'your_key',
    storefrontSecret: 'your_secret',
    config: [
        // Redis configuration
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'myapp_oauth:'
        ],
        
        // HTTP configuration
        'timeout' => 30,
        'ssl_verify' => true,
        
        // Custom API endpoints (if needed)
        'base_url' => 'https://custom.domain.com',
        'storefront_base_url' => 'https://mystore.custom.com/api',
        'admin_base_url' => 'https://mystore.custom.com/admin-api'
    ]
);
```

## Token Management

The SDK handles tokens automatically:

- **Automatic Caching**: Tokens are cached in Redis with proper expiration
- **Smart Refresh**: Expired tokens are refreshed automatically
- **Dual Storage**: Separate token storage for storefront and admin APIs
- **Session Management**: Handles OAuth sessions and nonce validation

### Manual Token Operations

```php
// Check authentication status
if ($client->isStorefrontAuthenticated()) {
    echo "Storefront is ready!";
}

if ($client->isAdminAuthenticated()) {
    echo "Admin is ready!";
}

// Force token refresh
$client->refreshTokens('storefront');
$client->refreshTokens('admin');
```

## Error Handling

The SDK throws `FlickSellException` for all API-related errors:

```php
try {
    $client->authenticate();
    $products = $client->requestStorefront('products');
} catch (FlickSellException $e) {
    echo "API Error: " . $e->getMessage();
    
    // Handle specific error cases
    if (str_contains($e->getMessage(), 'authentication failed')) {
        // Handle auth errors
    } elseif (str_contains($e->getMessage(), 'HTTP 404')) {
        // Handle not found errors
    }
}
```

## Advanced Usage

### File Uploads

```php
// Upload product image
$response = $client->requestAdmin('products/123/images', [], [
    'image' => new CURLFile('/path/to/image.jpg', 'image/jpeg', 'product.jpg'),
    'alt_text' => 'Product image'
]);
```

### Custom Headers & Advanced Requests

For more control, you can access the underlying HTTP client:

```php
// The SDK handles authentication headers automatically
$response = $client->requestStorefront('custom-endpoint', 
    ['param1' => 'value1'],  // GET params
    ['data' => 'value']      // POST data
);
```

## Requirements

- PHP 7.4 or higher
- Redis server (for token caching)
- cURL extension
- JSON extension

## Security

- All tokens are stored securely in Redis with proper expiration
- OAuth 2.0 flow with nonce and timestamp validation
- Automatic token refresh prevents stale authentication
- SSL verification enabled by default

## Support

For support, please contact the FlickSell development team or create an issue in the repository.

## License

MIT License - see LICENSE file for details. 