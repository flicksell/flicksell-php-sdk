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
$SITE_ID = 'site12345'; // Your FlickSell site ID

// Optional Redis configuration for nonce checking
$redisConfig = [
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
    'database' => 0
];

// Initialize FlickSell Auth (with 5-minute timestamp tolerance)
$auth = new FlickSellAuth($APP_KEY, $APP_SECRET, $SITE_ID, $redisConfig, 300);

// Handle initial verification from FlickSell
if (isset($_POST['flicksell_token']) && !isset($_SESSION['flicksell_verified'])) {
    echo "<h2>🔍 Verifying FlickSell Request...</h2>";
    
    $result = $auth->verifyRequest($_POST);
    
    if ($result['success']) {
        echo "<div style='color: green;'>✅ Authentication successful!</div>";
        echo "<p><strong>Site ID:</strong> " . htmlspecialchars($result['payload']['iss']) . "</p>";
        echo "<p><strong>App Handle:</strong> " . htmlspecialchars($result['payload']['app'] ?? 'N/A') . "</p>";
        echo "<p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s', $result['payload']['iat']) . "</p>";
        echo "<p><strong>Nonce:</strong> " . htmlspecialchars($result['payload']['nonce']) . "</p>";
        
        // Store verification in session
        $_SESSION['flicksell_verified'] = true;
        $_SESSION['flicksell_site_id'] = $result['payload']['iss'];
        $_SESSION['flicksell_app'] = $result['payload']['app'] ?? null;
        $_SESSION['verified_at'] = time();
        
        echo "<hr>";
        echo "<h3>🎉 Welcome to Your App!</h3>";
        echo "<p>You can now access app features securely.</p>";
        
    } else {
        echo "<div style='color: red;'>❌ Authentication failed!</div>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($result['message']) . "</p>";
        http_response_code(403);
        exit;
    }
}

// Check if user is verified (for subsequent page loads)
elseif (isset($_SESSION['flicksell_verified'])) {
    echo "<h2>🔒 Verified Session</h2>";
    echo "<p><strong>Site ID:</strong> " . htmlspecialchars($_SESSION['flicksell_site_id']) . "</p>";
    echo "<p><strong>App:</strong> " . htmlspecialchars($_SESSION['flicksell_app'] ?? 'N/A') . "</p>";
    echo "<p><strong>Verified at:</strong> " . date('Y-m-d H:i:s', $_SESSION['verified_at']) . "</p>";
    
    // Check if session is still fresh (within 1 hour)
    if ((time() - $_SESSION['verified_at']) > 3600) {
        echo "<div style='color: orange;'>⚠️ Session is older than 1 hour. Consider re-verification.</div>";
    }
    
    echo "<hr>";
    echo "<h3>🛠️ App Features</h3>";
    echo "<p>Your app functionality goes here...</p>";
}

// No verification present
else {
    echo "<h2>🚫 Access Denied</h2>";
    echo "<p>This app requires authentication from FlickSell.</p>";
    echo "<p>Please access this app through your FlickSell admin panel.</p>";
    http_response_code(401);
    exit;
}

// Example: Making an API call back to FlickSell
if (isset($_POST['test_api_call']) && isset($_SESSION['flicksell_verified'])) {
    echo "<hr>";
    echo "<h3>🌐 Testing API Call to FlickSell</h3>";
    
    try {
        // Use storefront credentials for this example
        $storefrontAuth = new FlickSellAuth(
            'stk_your_storefront_key_here',
            'your_storefront_secret_here',
            $_SESSION['flicksell_site_id']
        );
        
        $response = $storefrontAuth->sendAuthenticatedRequest(
            'https://yourstore.flicksell.com/api/get_users.php',
            [],
            'POST'
        );
        
        if ($response['success']) {
            echo "<div style='color: green;'>✅ API call successful!</div>";
            echo "<pre>" . htmlspecialchars($response['body']) . "</pre>";
        } else {
            echo "<div style='color: red;'>❌ API call failed!</div>";
            echo "<p>Error: " . htmlspecialchars($response['error'] ?? 'Unknown error') . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<div style='color: red;'>❌ Exception occurred!</div>";
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Example: Generate auth parameters manually
if (isset($_POST['show_auth_params']) && isset($_SESSION['flicksell_verified'])) {
    echo "<hr>";
    echo "<h3>🔑 Manual Auth Parameters</h3>";
    
    $params = $auth->generateAuthParams();
    echo "<pre>";
    print_r($params);
    echo "</pre>";
    
    echo "<p><em>Use these parameters for manual API calls with cURL or other HTTP clients.</em></p>";
}

// Example: Generate a token
if (isset($_POST['generate_token']) && isset($_SESSION['flicksell_verified'])) {
    echo "<hr>";
    echo "<h3>🎫 Generated Token</h3>";
    
    $token = $auth->generateToken([
        'custom_data' => 'example_value',
        'user_id' => 123
    ]);
    
    echo "<p><strong>Token:</strong></p>";
    echo "<textarea style='width: 100%; height: 100px;'>" . htmlspecialchars($token) . "</textarea>";
    echo "<p><em>This token can be used for iframe authentication or API calls.</em></p>";
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>FlickSell SDK Example</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .button:hover { background: #005a87; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; }
        hr { margin: 30px 0; border: none; border-top: 1px solid #ddd; }
    </style>
</head>
<body>

<?php if (isset($_SESSION['flicksell_verified'])): ?>
    <hr>
    <h3>🧪 Test Functions</h3>
    <form method="post" style="display: inline;">
        <button type="submit" name="test_api_call" class="button">Test API Call</button>
    </form>
    
    <form method="post" style="display: inline;">
        <button type="submit" name="show_auth_params" class="button">Show Auth Params</button>
    </form>
    
    <form method="post" style="display: inline;">
        <button type="submit" name="generate_token" class="button">Generate Token</button>
    </form>
    
    <form method="post" style="display: inline;">
        <button type="submit" name="clear_session" class="button" style="background: #dc3545;">Clear Session</button>
    </form>
<?php endif; ?>

<?php
// Handle session clearing
if (isset($_POST['clear_session'])) {
    session_destroy();
    echo "<script>window.location.reload();</script>";
}
?>

</body>
</html> 