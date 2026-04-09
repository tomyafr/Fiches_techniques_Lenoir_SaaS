<?php
/**
 * IA Helper - Intégration Groq pour Lenoir-Mec
 */

/**
 * Extrait les problèmes (AA, NC, NR, HS, R) des données de contrôle.
 */
function extractIssuesFromDonnees($donnees) {
    $labelsMap = [
        // APRF
        'aprf_satisfaction' => 'Satisfaction de fonctionnement',
        'aprf_bande' => 'État et type de la bande',
        'aprf_reglettes' => 'État des réglettes',
        'aprf_boutons' => 'État des boutons étoile',
        'aprf_options' => 'Options',
        'aprf_inox' => 'Caisson Inox',
        'aprf_attraction' => 'Contrôle de l\'attraction',
        'aprf_dist' => 'Distance aimants / bande',
        'aprf_haut' => 'Hauteur de la couche',
        'aprf_debit' => 'Débit',
        // EDX
        'edx_acces' => 'Accès au séparateur', 'edx_etat_gen' => 'Etat général du séparateur', 'edx_verrous' => 'Etat général des verrous',
        'edx_grenouilles' => 'Etat général des grenouillères', 'edx_poignees' => 'Etat général des poignées de portes', 'edx_carters' => 'Etat général des carters de protection',
        'edx_int_sep' => 'Aspect général intérieur séparateur', 'edx_etanch' => 'Contrôle visuel des étanchéités latérales', 'edx_bande_ext' => 'Contrôle visuel état extérieur de la bande',
        'edx_bande_int' => 'Contrôle visuel état intérieur de la bande', 'edx_tension_bande' => 'Contrôle de la tension de bande', 'edx_rlx_anti' => 'Contrôle état des rouleaux anti-déport de bande',
        'edx_detecteurs' => 'Contrôle état des détecteurs de déport de bande', 'edx_guides' => 'Contrôle état des guides TEFLON', 'edx_racleur' => 'Contrôle état du racleur de bande',
        'edx_racleur_regl' => 'Contrôle réglage du racleur de bande', 'edx_paliers_phuse' => 'Contrôle réglage des paliers PHUSE-TENDEURS', 'edx_tambour' => 'Contrôle état du tambour moteur',
        'edx_virole' => 'Contrôle visuel fibre virole roue polaire', 'edx_deflecteur' => 'Contrôle visuel état déflecteur carbone', 'edx_caisson_roue' => 'Contrôle visuel état caisson roue polaire',
        'edx_vis_virole' => 'Contrôle état général des vis de fixation virole', 'edx_ctrl_rot' => 'Contrôle état du contrôleur de rotation', 'edx_3e_rouleau' => 'Contrôle/repère réglage 3ème rouleau',
        'edx_rlx_mines' => 'Contrôle état des rouleaux "mines"', 'edx_motor' => 'Contrôle état motoréducteur entraînement bande', 'edx_dem_carter' => 'Démontage carter protection moteur',
        'edx_courroies' => 'Contrôle état des courroies', 'edx_tens_courroies' => 'Contrôle tension des courroies', 'edx_accoupl' => 'Contrôle état accouplement',
        'edx_align_mot' => 'Contrôle alignement moteur', 'edx_pal_fibre' => 'Contrôle état paliers/roulements virole fibre', 'edx_graiss_fibre' => 'Contrôle graissage paliers/roulements virole fibre',
        'edx_pal_roue' => 'Contrôle état paliers/roulements roue polaire', 'edx_graiss_roue' => 'Contrôle graissage paliers/roulements roue polaire', 'edx_induc_roue' => 'Contrôle induction roue polaire',
        'edx_cables' => 'Etat général des câbles, boîtiers, connexion', 'edx_nettoyage' => 'Nettoyage complet intérieur séparateur', 'edx_remontage' => 'Remontage carters protection/portes',
        'edx_B_verrous' => 'Etat général des verrous (Partie B)', 'edx_B_grenouilles' => 'Etat général des grenouillères (Partie B)', 'edx_B_poignees' => 'Etat général des poignées (Partie B)',
        'edx_B_portes' => 'Etat général carters/portes (Partie B)', 'edx_B_plex' => 'Etat général des plexis', 'edx_B_dem' => 'Démontage carters/portes (Partie B)',
        'edx_B_asp' => 'Aspect général intérieur caisson', 'edx_B_volet' => 'Contrôle état du volet', 'edx_B_meca' => 'Contrôle état mécanisme réglage volet',
        'edx_B_reglages' => 'Contrôles des réglages du volet', 'edx_B_net' => 'Nettoyage intérieur caisson', 'edx_B_rem' => 'Remontage carters/portes (Partie B)',
        'edx_C_arm' => 'Aspect général armoire électrique', 'edx_C_bout' => 'Aspect général boutonnerie façade', 'edx_C_au' => 'Etat général AU séparateur',
        'edx_C_ouvert' => 'Ouverture armoire électrique', 'edx_C_int' => 'Etat général intérieur armoire', 'edx_C_vit_b' => 'Vitesse bande relevée',
        'edx_C_vit_b_conf' => 'Vitesse bande conforme process', 'edx_C_regl1' => 'Nouveaux réglages réalisés (Bande)', 'edx_C_vit_r' => 'Vitesse roue polaire relevée',
        'edx_C_vit_r_conf' => 'Vitesse roue polaire conforme', 'edx_C_regl2' => 'Nouveaux réglages réalisés (Roue)', 'edx_C_regl3' => 'Nouveaux réglages volet',
        'edx_C_frein' => 'Contrôle freinage roue polaire', 'edx_C_temps' => 'Temps de freinage constaté', 'edx_C_cables' => 'Serrages câbles armoire', 'edx_C_ferm' => 'Fermeture armoire électrique',
        // OV
        'ov_acces' => 'Accès au séparateur', 'ov_etat_gen' => 'Etat général du séparateur', 'ov_bande' => 'Etat de la bande',
        'ov_pres_prot' => 'Présence protections latérales', 'ov_etat_prot' => 'Etat protections latérales', 'ov_pres_def' => 'Présences déflecteurs',
        'ov_etat_def' => 'Etat déflecteurs', 'ov_boulon' => 'Etat de la boulonnerie', 'ov_longeron' => 'Etat des longerons',
        'ov_cables' => 'Etat câbles et presse-étoupes', 'ov_moteur' => 'Modèle et état du moteur', 'ov_couple' => 'Etat du bras de couple',
        'ov_ctrl' => 'Etat contrôleur de rotation', 'ov_galets' => 'Etat galets anti-déport', 'ov_detect' => 'Etat détecteurs anti-déport',
        'ov_pal_phuse' => 'Etat paliers PHUSE tendeurs', 'ov_pal_mot' => 'Etat paliers tambour motorisé', 'ov_caisson' => 'Etat caisson acier inoxydable',
        'ov_conn' => 'Contrôle connexions boîte à bornes', 'ov_resist' => 'Mesure de résistance', 'ov_isol' => 'Mesure de l\'isolement',
        'ov_opt1' => 'Option 1', 'ov_opt2' => 'Option 2',
        // GENERIC / PAP / TAP
        'gen_fixation' => 'Fixation de l\'appareil', 'gen_sale' => 'Appareil sale / Nettoyage', 'gen_usure' => 'Usure importante des pièces',
        'gen_tension' => 'Tension courroies ou chaînes', 'gen_align' => 'Alignement pignons / poulies', 'gen_huile' => 'Graissage chaîne / Niveau d\'huile',
        'gen_bruit' => 'Échauffement ou Bruit suspect', 'gen_defauts' => 'Test déclenchement défauts', 'gen_au' => 'Tester bouton Arrêt d\'Urgence',
        'gen_mesures' => 'Mesure isolation & Induction',
        // LEVAGE
        'levage_tension' => 'Tension levage', 'levage_intensite' => 'Intensité levage', 'levage_champ_centre' => 'Champ magnétique au centre',
        'levage_champ_pole' => 'Champ magnétique au pôle',
        // NOUVELLES CLES ISSUES DU FORMULAIRE PAP/TAP
        'paptap_satisfaction' => 'Satisfaction fonctionnement PAP/TAP',
        'paptap_aspect' => 'Aspect général PAP/TAP',
        'paptap_virole' => 'État d’usure de la virole inox',
        'paptap_revetement' => 'Revêtement caoutchouc',
        'paptap_tasseaux' => 'Nombre et taille des tasseaux',
        'paptap_arbre' => 'État de l’arbre d’entrainement',
        'paptap_paliers' => 'État des paliers et graissage',
        'paptap_rotation' => 'Rotation sans difficulté',
        'paptap_pos_circuit' => 'Position correcte du circuit',
        'paptap_palier_fixe' => 'Bon maintien du palier fixe',
        // PLAQUES MAGNETIQUES PM
        'pm_satisfaction' => 'Satisfaction de fonctionnement',
        'pm_aspect' => 'Aspect général',
        'pm_induction_p1_stat' => 'Contrôle induction P1 (contre)',
        'pm_induction_p2_stat' => 'Contrôle induction P2 (bord largeur)',
        'pm_induction_p3_stat' => 'Contrôle induction P3 (bord longueur)',
        'pm_dist_ecrou_stat' => 'Distance attraction Ecrou M4',
        'pm_dist_rond50_stat' => 'Distance attraction Rond Ø6 Lg50',
        'pm_dist_rond100_stat' => 'Distance attraction Rond Ø6 Lg100',
        'pm_dist_bille_stat' => 'Distance attraction Bille Ø20',
        'pm_aimants' => 'Type d\'aimants (Ferrite/Néodyme)',
        'pm_temperature' => 'Température d\'utilisation',
        'pm_volet' => 'État du volet',
        'pm_chassis' => 'État des châssis',
        'pm_ressauts' => 'État des ressauts',
        // SEPARATEUR A GRILLES AUTOMATIQUE SGA
        'sga_satisfaction' => 'Satisfaction de fonctionnement',
        'sga_aspect' => 'Aspect général',
        'sga_etancheite' => 'Contrôle étanchéité (portes, brides, nourrices, vérins, volets)',
        'sga_coulissement' => 'Coulissement des barreaux',
        'sga_detect_circuit' => 'Détecteurs position circuit magnétique',
        'sga_detect_verins' => 'Détecteurs position vérins',
        'sga_verins' => 'Vérins (ouverture/fermeture volets)',
        'sga_limiteurs' => 'Limiteurs de débit barreaux',
        'sga_bagues' => 'Bagues coulissantes',
        'sga_e1_stat' => 'Étage 1 (supérieur) - barreaux',
        'sga_e2_stat' => 'Étage 2 - barreaux',
        'sga_e3_stat' => 'Étage 3 - barreaux',
        'sga_intervalle' => 'Intervalle cycles de nettoyage',
        'sga_etanch_coffret' => 'Étanchéité sous coffret',
        'sga_filtre_regulateur' => 'Filtre à air et régulateur de pression',
        'sga_produit_stat' => 'Type de produit',
        'sga_granulo_stat' => 'Granulométrie',
        'sga_debit_stat' => 'Débit',
        'sga_pression_stat' => 'Pression de service'
    ];

    $issues = ['aa' => [], 'nc' => [], 'nr' => []];
    $negativeValues = ['nc', 'nr', 'aa', 'r', 'hs', '+', 'a ameliorer', 'non conforme', 'a remplacer', 'endommagé', 'rouillé', 'sale', 'défectueux', 'usé', 'usées', 'faible'];

    // On scanne la map officielle pour l'ordre et les libellés précis
    $seenKeys = [];
    foreach ($labelsMap as $cleanKey => $label) {
        $val = null;
        $actualKey = null;

        foreach ([$cleanKey . '_radio', $cleanKey . '_stat', $cleanKey] as $kVariant) {
            if (isset($donnees[$kVariant])) {
                $val = trim(strtolower((string)$donnees[$kVariant]));
                $actualKey = $kVariant;
                $seenKeys[] = $actualKey;
                break;
            }
        }

        if ($val && in_array($val, $negativeValues)) {
            $comment = $donnees[$cleanKey . '_comment'] ?? $donnees[$actualKey . '_comment'] ?? null;
            $issue = ['designation' => $label, 'commentaire' => $comment];
            
            if ($val === 'nc' || $val === 'hs' || $val === 'non conforme') $issues['nc'][] = $issue;
            elseif ($val === 'nr' || $val === 'r' || $val === 'a remplacer' || $val === '+') $issues['nr'][] = $issue;
            else $issues['aa'][] = $issue;
        }
    }

    // NOUVEAUTÉ : On scanne TOUT le reste de $donnees pour ne rien rater (clés dynamiques ou nouvelles)
    foreach ($donnees as $key => $val) {
        if (in_array($key, $seenKeys)) continue;
        if (strpos($key, '_comment') !== false) continue;
        
        $valStr = trim(strtolower((string)$val));
        if ($valStr && in_array($valStr, $negativeValues)) {
            // "Humanisation" de la clé si inconnue
            $label = str_replace(['_', 'edx', 'ov', 'aprf', 'levage', 'radio', 'stat'], [' ', '', '', '', '', '', ''], $key);
            $label = trim(ucfirst($label));
            
            $comment = $donnees[$key . '_comment'] ?? null;
            $issue = ['designation' => $label, 'commentaire' => $comment];

            if ($valStr === 'nc' || $valStr === 'hs' || $valStr === 'non conforme') $issues['nc'][] = $issue;
            elseif ($valStr === 'nr' || $valStr === 'r' || $valStr === 'a remplacer' || $valStr === '+') $issues['nr'][] = $issue;
            else $issues['aa'][] = $issue;
        }
    }

    return $issues;
}

/**
 * Appelle l'API Groq Cloud
 */
function callGroqIA($systemPrompt, $userPrompt, $options = []) {
    if (!defined('GROQ_API_KEY') || empty(GROQ_API_KEY)) {
        return null; // Fallback géré par l'appelant
    }

    $temperature = $options['temperature'] ?? 0.2;
    $max_tokens = $options['max_tokens'] ?? 600;
    $top_p = $options['top_p'] ?? 1.0;
    $frequency_penalty = $options['frequency_penalty'] ?? 0.0;
    $presence_penalty = $options['presence_penalty'] ?? 0.0;

    $payload = [
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        'top_p' => $top_p,
        'frequency_penalty' => $frequency_penalty,
        'presence_penalty' => $presence_penalty
    ];


    // Debug intern (sera visible dans les logs php)
    error_log("Groq API Call - Key start: " . substr(GROQ_API_KEY, 0, 7) . "... End: " . substr(GROQ_API_KEY, -4));
    
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . trim(GROQ_API_KEY),
        'Content-Type: application/json'
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25); // Augmenté à 25s pour éviter les micro-couures
    
    // Debug SSL : Si ton serveur a des vieux certificats, curl peut bloquer
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? null;
    }

    // Retourne l'erreur pour affichage dans l'interface (debug)
    return "Erreur IA (Code $httpCode) : " . ($error ?: "Réponse invalide du serveur Groq");
}
