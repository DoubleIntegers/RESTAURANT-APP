<?php
/**
 * import.php
 * Jalankan SEKALI lewat browser: http://localhost/<folder>/import.php
 * atau lewat CMD: php import.php
 */
require_once 'config.php';

$file = __DIR__ . '/restaurants.json';
if (!file_exists($file)) {
    die("<b>ERROR:</b> File <code>restaurants.json</code> tidak ditemukan di folder ini.");
}

try {
    $client     = new MongoDB\Client(MONGO_URI);
    $collection = $client->selectCollection(MONGO_DB, MONGO_COLLECTION);

    $collection->drop();
    echo "✅ Collection lama dihapus.<br>\n";
    flush();

    $lines     = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $batch     = [];
    $batchSize = 200;
    $total     = 0;

    foreach ($lines as $line) {
        $doc = json_decode($line, true);
        if (!$doc) continue;

        if (isset($doc['grades']) && is_array($doc['grades'])) {
            foreach ($doc['grades'] as &$grade) {
                if (isset($grade['date']['$date'])) {
                    $grade['date'] = new MongoDB\BSON\UTCDateTime($grade['date']['$date']);
                }
            }
            unset($grade);
        }

        $batch[] = $doc;

        if (count($batch) >= $batchSize) {
            $collection->insertMany($batch);
            $total += count($batch);
            $batch  = [];
            echo "Inserted <b>$total</b> records...<br>\n";
            flush();
        }
    }

    if (!empty($batch)) {
        $collection->insertMany($batch);
        $total += count($batch);
    }

    $collection->createIndex(['borough' => 1]);
    $collection->createIndex(['cuisine' => 1]);

    echo "<br>✅ <b>Selesai!</b> $total restaurants berhasil diimport.<br>\n";
    echo "➡️ <a href='index.php'>Buka Restaurant Explorer</a>\n";

} catch (Exception $e) {
    echo "<b>ERROR:</b> " . $e->getMessage();
}
