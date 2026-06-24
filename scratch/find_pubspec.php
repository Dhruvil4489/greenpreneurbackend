<?php
function scan_pubspec($dir) {
    $results = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        // Skip common large directories
        if (strpos($path, 'vendor') !== false 
            || strpos($path, 'node_modules') !== false 
            || strpos($path, '.git') !== false 
            || strpos($path, '$RECYCLE.BIN') !== false
            || strpos($path, 'System Volume Information') !== false) {
            continue;
        }
        if ($file->isFile() && $file->getFilename() === 'pubspec.yaml') {
            $results[] = $path;
        }
    }
    return $results;
}

echo "=== SCANNING FOR PUBSPEC.YAML ===\n";
$all = scan_pubspec('D:\\');
foreach ($all as $f) {
    echo "- {$f}\n";
}
