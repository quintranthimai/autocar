<?php

$dbConn = pg_connect("host=aws-0-ap-northeast-1.pooler.supabase.com port=6543 dbname=postgres user=postgres.koreltihkymckcsfsehf password=autocar-db1");

if (!$dbConn) {
    die("Connection failed\n");
}

$imagesDir = __DIR__ . '/storage/app/public/vehicles/images';
$existingFiles = array_diff(scandir($imagesDir), ['.', '..']);
$validUrls = [];

foreach ($existingFiles as $file) {
    $validUrls[] = 'https://autocar-citx.onrender.com/storage/vehicles/images/' . $file;
}

if (empty($validUrls)) {
    die("No valid images found.\n");
}

$result = pg_query($dbConn, "SELECT id, image_url FROM vehicle_images");
$updatedCount = 0;

while ($row = pg_fetch_assoc($result)) {
    $url = $row['image_url'];
    $filename = basename($url);
    
    if (!in_array($filename, $existingFiles)) {
        // File does not exist, pick a random valid URL
        $newUrl = $validUrls[array_rand($validUrls)];
        
        pg_query_params($dbConn, "UPDATE vehicle_images SET image_url = $1 WHERE id = $2", [$newUrl, $row['id']]);
        $updatedCount++;
    }
}

echo "Fixed $updatedCount missing vehicle images!\n";
pg_close($dbConn);

