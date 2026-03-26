<?php
require_once __DIR__ . '/../includes/config.php';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RGPD & Confidentialité | LM Expert</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-mode');
        }
    </script>
</head>

<body class="bg-main">
    <div class="layout" style="display: flex; justify-content: center; padding: 2rem;">
        <div class="card glass animate-in" style="max-width: 800px; line-height: 1.6;">
            <div style="text-align: center; margin-bottom: 3rem;">
                <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" style="height: 50px; margin-bottom: 1rem;">
                <h1 style="font-size: 2rem;">Protection des Données</h1>
            </div>

            <section style="margin-bottom: 2rem;">
                <h2 style="color: var(--primary); margin-bottom: 1rem;">1. Responsable du traitement</h2>
                <p>Le traitement des données est effectué par <strong>Raoul Lenoir SAS</strong> (LENOIR-MEC),
                    responsable de l'outil interne de pointage.</p>
            </section>

            <section style="margin-bottom: 2rem;">
                <h2 style="color: var(--primary); margin-bottom: 1rem;">2. Finalité de la collecte</h2>
                <p>Les données collectées sont strictement limitées à :</p>
                <ul style="margin-left: 2rem; margin-top: 0.5rem; list-style: disc;">
                    <li>Le suivi des heures de production par Ordre de Fabrication (OF).</li>
                    <li>La synchronisation avec l'ERP Microsoft Business Central.</li>
                    <li>La sécurisation des accès via des logs d'audit.</li>
                </ul>
            </section>

            <section style="margin-bottom: 2rem;">
                <h2 style="color: var(--primary); margin-bottom: 1rem;">3. Données collectées</h2>
                <p>Nous traitons uniquement les informations professionnelles nécessaires : Nom, Prénom, identifiant
                    interne, et les logs de pointage (date, heures, OF).</p>
            </section>

            <section style="margin-bottom: 2rem;">
                <h2 style="color: var(--primary); margin-bottom: 1rem;">4. Durée de conservation</h2>
                <p>Les données de pointage sont conservées conformément aux obligations légales de l'entreprise en
                    matière de droit du travail et de comptabilité.</p>
            </section>

            <section style="margin-bottom: 2rem;">
                <h2 style="color: var(--primary); margin-bottom: 1rem;">5. Vos droits</h2>
                <p>Conformément au RGPD, vous disposez d'un droit d'accès, de rectification et de suppression de vos
                    données personnelles. Pour exercer ces droits, veuillez contacter le service informatique de Raoul
                    Lenoir.</p>
            </section>

            <div style="margin-top: 4rem; text-align: center;">
                <a href="index.php" class="btn btn-ghost">Retour à la connexion</a>
            </div>
        </div>
    </div>
</body>

</html>