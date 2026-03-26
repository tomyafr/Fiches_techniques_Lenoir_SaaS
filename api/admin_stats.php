<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth('admin');

header('Content-Type: application/json');

$db = getDB();

try {
    $stats = [];

    // 1. Interventions par statut
    $stmt = $db->query("SELECT statut, COUNT(*) as count FROM interventions GROUP BY statut");
    $stats['status'] = $stmt->fetchAll();

    // 2. Volume mensuel (6 derniers mois, incluant les mois à 0)
    $stmt = $db->query("
        SELECT 
            TO_CHAR(m, 'YYYY-MM') as mois,
            COALESCE(counts.count, 0) as count
        FROM generate_series(
            CURRENT_DATE - INTERVAL '5 months', 
            CURRENT_DATE, 
            '1 month'
        ) m
        LEFT JOIN (
            SELECT 
                TO_CHAR(date_intervention, 'YYYY-MM') as month_label, 
                COUNT(*) as count 
            FROM interventions 
            WHERE date_intervention >= CURRENT_DATE - INTERVAL '6 months'
            GROUP BY month_label
        ) counts ON TO_CHAR(m, 'YYYY-MM') = counts.month_label
        ORDER BY mois ASC
    ");
    $stats['monthly'] = $stmt->fetchAll();

    // 3. Top 5 Clients
    $stmt = $db->query("
        SELECT 
            c.nom_societe, 
            COUNT(i.id) as count 
        FROM interventions i
        JOIN clients c ON i.client_id = c.id
        GROUP BY c.nom_societe 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stats['top_clients'] = $stmt->fetchAll();

    // 4. Score de conformité moyen
    // On calcule le ratio de 'c', 'bon', ou 'OK' par rapport au total des points renseignés
    $stmt = $db->query("
        WITH machine_points AS (
            SELECT 
                m.id,
                (SELECT COUNT(*) FROM jsonb_each_text(m.donnees_controle) WHERE value IN ('c', 'bon', 'OK')) as points_ok,
                (SELECT COUNT(*) FROM jsonb_each_text(m.donnees_controle) WHERE value NOT LIKE '%comment%') as points_total
            FROM machines m
            WHERE m.donnees_controle IS NOT NULL AND m.donnees_controle <> '{}'
        )
        SELECT 
            ROUND(AVG(CASE WHEN points_total > 0 THEN (points_ok::float / points_total) * 100 ELSE NULL END)) as avg_compliance
        FROM machine_points
    ");
    $compliance = $stmt->fetch();
    $stats['compliance'] = (int)($compliance['avg_compliance'] ?? 0);

    echo json_encode(['success' => true, 'data' => $stats]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
