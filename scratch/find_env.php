<?php
// Scan all directories recursively and print env files or files with config.
function scan($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        // Ignore vendor and node_modules directories
        if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false || strpos($path, '.git') !== false) {
            continue;
        }
        $name = $file->getFilename();
        if (strpos($name, '.env') !== false || strpos(strtolower($name), 'config') !== false || strpos(strtolower($name), 'constants') !== false) {
            $files[] = $path;
        }
    }
    return $files;
}

echo "=== SCANNING FOR CONFIG/ENV FILES ===\n";
$all = scan('D:\Greenpreneur');
foreach ($all as $f) {
    echo "- {$f}\n";
}
