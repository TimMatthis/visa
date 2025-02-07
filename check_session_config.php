<?php
// Display all session-related configuration settings
echo "<h1>Session Configuration</h1>";
echo "<pre>";

// Display specific session settings
echo "session.save_path: " . ini_get('session.save_path') . "\n";
echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . "\n";
echo "session.cookie_secure: " . ini_get('session.cookie_secure') . "\n";
echo "session.cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
echo "session.use_strict_mode: " . ini_get('session.use_strict_mode') . "\n";

// Display all PHP configuration settings
phpinfo(INFO_CONFIGURATION);

echo "</pre>";
?> 