<?php
require_once __DIR__ . '/../includes/config.php';

$db = getDB();

echo "--- DÉPLACEMENT DES PHOTOS VERS UNE TABLE DÉDIÉE ---\n";

try {
    // 1. Création de la table
    $db->exec("CREATE TABLE IF NOT EXISTS machine_photos (
        id SERIAL PRIMARY KEY,
        machine_id INT NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
        field_key VARCHAR(100) NOT NULL,
        data TEXT NOT NULL,
        caption TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_machine_photos_machine ON machine_photos(machine_id)");
    echo "OK: Table machine_photos prête.\n";

    // 2. Migration des données
    $stmt = $db->query("SELECT id, photos FROM machines WHERE photos IS NOT NULL AND photos != '{}'");
    $machines = $stmt->fetchAll();

    echo "INFO: Found " . count($machines) . " machines with photos. Migration starting...\n";

    $count = 0;
    foreach ($machines as $m) {
        $photosData = json_decode($m['photos'], true);
        if (!$photosData) continue;

        $newPhotosJson = []; // On gardera une version légère du JSON dans la table machines

        foreach ($photosData as $key => $photos) {
            if (!is_array($photos)) continue;
            
            $newPhotosJson[$key] = [];

            foreach ($photos as $p) {
                if (empty($p['data'])) {
                    // Si la photo n'a déjà plus de data mais a un ID, on la garde dans le JSON léger tel quel
                    if (!empty($p['id'])) {
                         $newPhotosJson[$key][] = $p;
                    }
                    continue;
                }

                // Insertion dans la nouvelle table
                $ins = $db->prepare("INSERT INTO machine_photos (machine_id, field_key, data, caption) VALUES (?, ?, ?, ?) RETURNING id");
                $ins->execute([
                    $m['id'],
                    $key,
                    $p['data'],
                    $p['caption'] ?? ''
                ]);
                $newPhotoId = $ins->fetchColumn();

                // On garde une version légère sans la data base64
                $newPhotosJson[$key][] = [
                    'id' => $newPhotoId,
                    'caption' => $p['caption'] ?? ''
                ];
                $count++;
            }
        }

        // 3. Mise à jour de la table machines avec le JSON léger
        $update = $db->prepare("UPDATE machines SET photos = ? WHERE id = ?");
        $update->execute([json_encode($newPhotosJson), $m['id']]);
    }

    echo "SUCCÈS: $count photos déplacées avec succès !\n";

} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}
