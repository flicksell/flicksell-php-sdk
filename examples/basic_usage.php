<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FlickSell\Auth\FlickSellClient;
use FlickSell\Auth\Exceptions\FlickSellException;

try {
    // Initialize FlickSell client
    $client = new FlickSellClient(
        siteName: 'mystore',
        storefrontKey: 'myapp_sf_key_12345',
        storefrontSecret: 'myapp_sf_secret_abcdef',
        adminKey: 'myapp_adm_key_67890',
        adminSecret: 'myapp_adm_secret_ghijkl',
        config: [
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0
            ],
            'timeout' => 30,
            'ssl_verify' => true
        ]
    );

    // Authenticate (this will handle tokens automatically)
    $client->authenticate();

    // Make storefront API requests
    echo "=== Storefront API Requests ===\n";
    
    // Get products
    $products = $client->requestStorefront('products', ['limit' => 10]);
    echo "Products: " . $products . "\n\n";
    
    // Get specific product
    $product = $client->requestStorefront('products/123');
    echo "Product 123: " . $product . "\n\n";

    // Make admin API requests
    echo "=== Admin API Requests ===\n";
    
    // Get orders
    $orders = $client->requestAdmin('orders', ['status' => 'pending']);
    echo "Pending Orders: " . $orders . "\n\n";
    
    // Create a new product (POST request)
    $newProduct = $client->requestAdmin('products', [], [
        'name' => 'New Product',
        'price' => 29.99,
        'description' => 'This is a new product created via API'
    ]);
    echo "New Product Created: " . $newProduct . "\n\n";

    // Check authentication status
    if ($client->isStorefrontAuthenticated()) {
        echo "✅ Storefront is authenticated\n";
    }
    
    if ($client->isAdminAuthenticated()) {
        echo "✅ Admin is authenticated\n";
    }

} catch (FlickSellException $e) {
    echo "❌ FlickSell Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ General Error: " . $e->getMessage() . "\n";
} 