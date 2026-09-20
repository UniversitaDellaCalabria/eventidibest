<?php
// admin/cron_backup.php - Backup FULL (Database PURE PHP + File ZIP del sito)

// Rimuoviamo i limiti di tempo e memoria di PHP (zippare i file richiede risorse)
set_time_limit(0);
ini_set('memory_limit', '512M');

// Includiamo la connessione per il database
require_once '../config.php';

// --- CONFIGURAZIONE CARTELLA ---
$backup_dir = __DIR__ . '/../backups/';

if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
    file_put_contents($backup_dir . '.htaccess', "Require all denied");
}

$date = date('Y-m-d_H-i-s');
$sql_filename = $backup_dir . "backup_DB_{$date}.sql";
$zip_filename = $backup_dir . "backup_SITO_{$date}.zip";

echo "<div style='font-family: sans-serif; background: #f8f9fa; padding: 20px; border-radius: 8px; max-width: 800px; margin: 20px auto; border: 1px solid #dee2e6;'>";
echo "<h2 style='color: #0d6efd; margin-top: 0;'>🔄 Avvio Backup Completo...</h2>";

// ==========================================
// 1. BACKUP DEL DATABASE (PURE PHP)
// ==========================================
$sql_dump = "-- Backup del Database Eventi DiBEST\n";
$sql_dump .= "-- Generato il: " . date('Y-m-d H:i:s') . "\n\n";
$sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

$tables = [];
$result = $conn->query("SHOW TABLES");
if ($result) { while ($row = $result->fetch_row()) { $tables[] = $row[0]; } }

foreach ($tables as $table) {
    $result = $conn->query("SELECT * FROM `$table`");
    $num_cols = $result->field_count;

    $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
    $sql_dump .= $row2[1] . ";\n\n";

    while ($row = $result->fetch_row()) {
        $sql_dump .= "INSERT INTO `$table` VALUES(";
        for ($j = 0; $j < $num_cols; $j++) {
            if (!isset($row[$j])) {
                $sql_dump .= "NULL";
            } else {
                $val = $conn->real_escape_string($row[$j]);
                $val = str_replace("\n", "\\n", $val);
                $val = str_replace("\r", "\\r", $val);
                $sql_dump .= "'" . $val . "'";
            }
            if ($j < ($num_cols - 1)) { $sql_dump .= ","; }
        }
        $sql_dump .= ");\n";
    }
    $sql_dump .= "\n\n";
}
$sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

if (file_put_contents($sql_filename, $sql_dump) !== false) {
    echo "<p style='color: #198754;'><strong>✅ 1. Database esportato correttamente:</strong> " . basename($sql_filename) . "</p>";
} else {
    echo "<p style='color: #dc3545;'><strong>❌ 1. Errore salvataggio Database.</strong> Permessi cartella insufficienti.</p>";
}

// ==========================================
// 2. BACKUP DEI FILE (ZIPARCHIVE)
// ==========================================
if (extension_loaded('zip')) {
    $zip = new ZipArchive();
    if ($zip->open($zip_filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        
        $rootPath = realpath(__DIR__ . '/../'); // La cartella principale del portale (es. /eventi)
        
        // Cerca tutti i file in modo ricorsivo
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $file_count = 0;
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                
                // ESCLUSIONE CRITICA: Saltiamo la cartella "backups" stessa!
                if (strpos($relativePath, 'backups') !== 0) {
                    $zip->addFile($filePath, $relativePath);
                    $file_count++;
                }
            }
        }
        $zip->close();
        echo "<p style='color: #198754;'><strong>✅ 2. File del sito compressi con successo:</strong> " . basename($zip_filename) . " ($file_count file inclusi)</p>";
    } else {
        echo "<p style='color: #dc3545;'><strong>❌ 2. Errore:</strong> Impossibile creare il file ZIP. Verifica i permessi della cartella.</p>";
    }
} else {
    echo "<p style='color: #ffc107;'><strong>⚠️ 2. Compressione ZIP non supportata:</strong> L'estensione PHP 'ZipArchive' non è attiva sul server.</p>";
}

// ==========================================
// 3. PULIZIA VECCHI BACKUP (Tiene solo 7 gg)
// ==========================================
$now = time();
$giorni_da_mantenere = 7;
$deleted_count = 0;

// Pulisce sia i vecchi .sql che i vecchi .zip
$all_backups = glob($backup_dir . "backup_*.*");

foreach ($all_backups as $file) {
    if (is_file($file)) {
        if ($now - filemtime($file) >= 60 * 60 * 24 * $giorni_da_mantenere) {
            unlink($file);
            $deleted_count++;
        }
    }
}

echo "<hr><p style='color: #6c757d; font-size: 0.9rem;'>🧹 Pulizia manutentiva: $deleted_count file obsoleti eliminati (Mantengo solo gli ultimi $giorni_da_mantenere giorni).</p>";
echo "<h3 style='color: #198754; text-align: center; margin-bottom: 0;'>🎉 Operazione Conclusa!</h3>";
echo "</div>";
?>
