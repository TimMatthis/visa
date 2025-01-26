<?php
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Extension directory: " . get_cfg_var('extension_dir') . "\n";
echo "Loaded extensions:\n";
print_r(get_loaded_extensions());
echo "\nPDO drivers:\n";
if (class_exists('PDO')) {
    print_r(PDO::getAvailableDrivers());
} else {
    echo "PDO class not found\n";
}
?> 