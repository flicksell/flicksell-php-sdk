<?php
/**
 * FlickSell SDK Example: App-Side Verification
 * 
 * This example shows how to verify incoming requests from FlickSell
 * and handle subsequent app operations securely.
 */

require_once '../vendor/autoload.php';

use FlickSell\FlickSellAuth;

// Start session for storing verification state
session_start();

// Your app's configuration
$APP_KEY = 'adk_your_admin_key_from_flicksell_here';
$APP_SECRET = 'your_admin_secret_from_flicksell_here';
$SITENAME = 'YourStoreName'; // Your FlickSell store name

// Optional Redis configuration for nonce checking
$redisConfig = [
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
    'database' => 0
];

// Initialize FlickSell Auth (with 5-minute timestamp tolerance)
$auth = new FlickSellAuth($APP_KEY, $APP_SECRET, $SITENAME, $redisConfig, 300);

// Handle initial verification from FlickSell
if (isset($_POST['flicksell_token']) && !isset($_SESSION['flicksell_verified'])) {
    echo "<h2>🔍 Verifying FlickSell Request...</h2>";
    
    $payload = $auth->verifyRequest($_POST);
    
    if ($payload) {
        echo "<div style='color: green;'>✅ <strong>Valid FlickSell Request!</strong></div>";
        echo "<ul>";
        echo "<li><strong>Store:</strong> " . htmlspecialchars($payload['iss']) . "</li>";
        echo "<li><strong>Timestamp:</strong> " . date('Y-m-d H:i:s', $payload['iat']) . "</li>";
        echo "<li><strong>Nonce:</strong> " . htmlspecialchars($payload['nonce']) . "</li>";
        echo "<li><strong>App:</strong> " . htmlspecialchars($payload['app'] ?? 'N/A') . "</li>";
        echo "<li><strong>Redis Available:</strong> " . ($auth->isRedisAvailable() ? 'Yes' : 'No') . "</li>";
        echo "</ul>";
        
        // Store verification in session
        $_SESSION['flicksell_verified'] = true;
        $_SESSION['flicksell_data'] = $payload;
        $_SESSION['verified_at'] = time();
        
        echo "<p>✅ Session established. You can now use the app!</p>";
        
    } else {
        echo "<div style='color: red;'>❌ <strong>Invalid Request</strong></div>";
        echo "<p>This request did not come from a valid FlickSell store.</p>";
        exit;
    }
}

// Check if we have a valid session
if (isset($_SESSION['flicksell_verified'])) {
    $sessionAge = time() - $_SESSION['verified_at'];
    $maxSessionAge = 3600; // 1 hour
    
    if ($sessionAge > $maxSessionAge) {
        echo "<div style='color: orange;'>⚠️ Session expired. Please reload the app.</div>";
        session_destroy();
        exit;
    }
    
    echo "<h2>🎉 App Loaded Successfully!</h2>";
    echo "<p><strong>Store:</strong> " . htmlspecialchars($_SESSION['flicksell_data']['iss']) . "</p>";
    echo "<p><strong>Session Age:</strong> " . $sessionAge . " seconds</p>";
    echo "<p><strong>API Key:</strong> " . htmlspecialchars($auth->getKey()) . "</p>";
    echo "<p><strong>Sitename:</strong> " . htmlspecialchars($auth->getSitename()) . "</p>";
    
    // Example: Make an API call back to FlickSell
    if (isset($_POST['test_api'])) {
        echo "<h3>🚀 Testing API Call to FlickSell...</h3>";
        
        $response = $auth->sendAuthenticatedRequest(
            'https://yourstore.flicksell.com/api/users',
            ['action' => 'get_users']
        );
        
        if ($response['success']) {
            echo "<div style='color: green;'>✅ API call successful!</div>";
            echo "<p><strong>Status Code:</strong> " . $response['status_code'] . "</p>";
            echo "<pre>" . htmlspecialchars($response['body']) . "</pre>";
        } else {
            echo "<div style='color: red;'>❌ API call failed</div>";
            echo "<p><strong>Error:</strong> " . htmlspecialchars($response['error']) . "</p>";
            echo "<p><strong>Status:</strong> " . $response['status_code'] . "</p>";
            if ($response['body']) {
                echo "<p><strong>Response:</strong> " . htmlspecialchars($response['body']) . "</p>";
            }
        }
    }
    
    // Example: Generate auth parameters manually
    if (isset($_POST['show_auth_params'])) {
        echo "<h3>🔧 Generated Auth Parameters</h3>";
        $params = $auth->generateAuthParams();
        echo "<pre>" . htmlspecialchars(json_encode($params, JSON_PRETTY_PRINT)) . "</pre>";
        echo "<p><em>These parameters can be used for manual API calls.</em></p>";
    }
    
    // App interface
    echo "<hr>";
    echo "<h3>App Controls</h3>";
    echo "<form method='post' style='margin-bottom: 10px;'>";
    echo "<button type='submit' name='test_api' value='1'>Test API Call to FlickSell</button>";
    echo "</form>";
    
    echo "<form method='post' style='margin-bottom: 10px;'>";
    echo "<button type='submit' name='show_auth_params' value='1'>Show Auth Parameters</button>";
    echo "</form>";
    
    echo "<form method='post'>";
    echo "<button type='submit' name='logout' value='1'>Logout (Clear Session)</button>";
    echo "</form>";
    
    // Handle logout
    if (isset($_POST['logout'])) {
        session_destroy();
        echo "<script>window.location.reload();</script>";
    }
    
} else {
    // No valid session - show instructions
    echo "<h2>FlickSell App Example</h2>";
    echo "<p>This app is waiting for a valid FlickSell request.</p>";
    echo "<p>To test:</p>";
    echo "<ol>";
    echo "<li>Install this app in your FlickSell store</li>";
    echo "<li>Set the app's website URL to this page</li>";
    echo "<li>Configure your app credentials in this file</li>";
    echo "<li>Open the app from your FlickSell admin panel</li>";
    echo "</ol>";
    
    echo "<h3>Configuration</h3>";
    echo "<p>Update these values in the PHP file:</p>";
    echo "<ul>";
    echo "<li><strong>APP_KEY:</strong> Your admin_key from FlickSell (starts with 'adk_')</li>";
    echo "<li><strong>APP_SECRET:</strong> Your admin_secret from FlickSell</li>";
    echo "<li><strong>SITENAME:</strong> Your FlickSell store name</li>";
    echo "</ul>";
    
    // For testing purposes, show a manual form
    echo "<hr>";
    echo "<h3>Manual Testing</h3>";
    echo "<p>For development, you can manually send a token:</p>";
    echo "<form method='post'>";
    echo "<label>FlickSell Token:</label><br>";
    echo "<textarea name='flicksell_token' rows='3' cols='80' placeholder='Paste JWT-like token here...'></textarea><br><br>";
    echo "<button type='submit'>Verify Token</button>";
    echo "</form>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>FlickSell App Example</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
        button { padding: 8px 16px; margin: 4px; }
        textarea { width: 100%; }
        ul, ol { margin: 10px 0; }
        li { margin: 5px 0; }
    </style>
</head>
</html> 