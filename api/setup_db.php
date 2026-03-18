<?php
require_once __DIR__ . '/../includes/config.php';

echo "<pre>";
echo "Starting DB Setup...\n";

try {
    $db = getDB();

    $users = [
        ['nom' => 'CHRIST', 'prenom' => 'Olivier', 'role' => 'chef'],
        ['nom' => 'SALAH', 'prenom' => 'Soufyane', 'role' => 'operateur'],
        ['nom' => 'BUDIN', 'prenom' => 'Aymeric', 'role' => 'operateur'],
        ['nom' => 'MANGIN', 'prenom' => 'Maxime', 'role' => 'operateur'],
        ['nom' => 'LAFOND', 'prenom' => 'Vivian', 'role' => 'operateur'],
        ['nom' => 'CHRISTIANY', 'prenom' => 'Jean-Paul', 'role' => 'operateur'],
        ['nom' => 'TONETTO', 'prenom' => 'Jean-Marc', 'role' => 'operateur'],
    ];

    // Password hash for 'password123'
    $hash = '$2y$12$Es7rLPLN9fKc7k6mxeGtVurg3.0nPqbb6.EJmJ6x/XkI7t1LFPFAC';

    $stmt = $db->prepare("INSERT INTO users (nom, prenom, password_hash, role) VALUES (?, ?, ?, ?) ON CONFLICT (nom) DO UPDATE SET prenom = EXCLUDED.prenom, role = EXCLUDED.role");

    foreach ($users as $user) {
        $stmt->execute([$user['nom'], $user['prenom'], $hash, $user['role']]);
        echo "User added/updated: {$user['nom']} {$user['prenom']} ({$user['role']})\n";
    }

    echo "\nSetup completed successfully!\n";
    echo "You can now log in with the last name (e.g., CHRIST or LOTITO) and password: password123\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
echo "</pre>";
