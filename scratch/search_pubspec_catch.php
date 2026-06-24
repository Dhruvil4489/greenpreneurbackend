<?php
function scanDirCustom($dir) {
    $results = [];
    try {
        $files = scandir($dir);
        if ($files === false) return [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            // Skip common system and large vendor folders to speed up
            if (strpos($path, 'vendor') !== false 
                || strpos($path, 'node_modules') !== false 
                || strpos($path, '.git') !== false 
                || strpos($path, '$RECYCLE.BIN') !== false
                || strpos($path, 'System Volume Information') !== false) {
                continue;
            }
            
            if (is_dir($path)) {
                $results = array_merge($results, scanDirCustom($path));
            } elseif ($file === 'pubspec.yaml') {
                $results[] = $path;
            }
        }
    } catch (\Exception $e) {
        // Ignore permission denied
    }
    return $results;
}

echo "=== SCANNING D: FOR PUBSPEC ===\n";
$found = scanDirCustom('D:');
foreach ($found as $f) {
    echo "- {$f}\n";
}
