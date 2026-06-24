<?php
// Scan all directories recursively for references to com.unity.greenpreneur
function search_pkg($dir) {
    $results = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isDir()) continue;
        $path = $file->getPathname();
        if (strpos($path, 'vendor') !== false || strpos($path, 'node_modules') !== false || strpos($path, '.git') !== false) {
            continue;
        }
        $content = file_get_contents($path);
        if (strpos($content, 'com.unity.greenpreneur') !== false) {
            $results[] = $path;
        }
    }
    return $results;
}

echo "=== SCANNING FOR PACKAGE REFERENCES ===\n";
$res = search_pkg('D:\Greenpreneur');
foreach ($res as $f) {
    echo "- {$f}\n";
}
