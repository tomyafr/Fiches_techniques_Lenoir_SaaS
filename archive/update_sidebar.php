<?php
$directory = 'c:/Users/tomdu/Desktop/saas_lenoir_fiches_techniques/api';
$files = ['admin.php', 'technicien.php', 'historique.php', 'equipe.php', 'profile.php'];

$template = '            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <?php if (!isset($forceChange) || !$forceChange): ?>
                    <a href="<?= $_SESSION[\'role\'] === \'admin\' ? \'admin.php\' : \'technicien.php\' ?>" class="btn btn-<?= basename($_SERVER[\'PHP_SELF\']) == \'admin.php\' || basename($_SERVER[\'PHP_SELF\']) == \'technicien.php\' ? \'primary\' : \'ghost\' ?> sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <span>📊</span> Tableau de bord
                    </a>
                    
                    <?php if ($_SESSION[\'role\'] === \'admin\'): ?>
                    <a href="admin.php?new=1" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <span>➕</span> Nouvelle Fiche
                    </a>
                    <?php else: ?>
                    <a href="technicien.php?new=1" class="btn btn-ghost sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <span>➕</span> Nouvelle Fiche
                    </a>
                    <?php endif; ?>

                    <a href="historique.php" class="btn btn-<?= basename($_SERVER[\'PHP_SELF\']) == \'historique.php\' ? \'primary\' : \'ghost\' ?> sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <span>🕒</span> Historique
                    </a>
                    <?php if ($_SESSION[\'role\'] === \'admin\'): ?>
                    <a href="equipe.php" class="btn btn-<?= basename($_SERVER[\'PHP_SELF\']) == \'equipe.php\' ? \'primary\' : \'ghost\' ?> sidebar-link"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                        <span>👥</span> Équipe
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase;">Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                    <p style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                        <?= htmlspecialchars($_SESSION[\'user_prenom\'] . \' \' . $_SESSION[\'user_nom\']) ?>
                    </p>
                </div>
                
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; color: var(--error); margin-bottom: 0.4rem;">
                    <span>🚪</span> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-<?= basename($_SERVER[\'PHP_SELF\']) == \'profile.php\' ? \'primary\' : \'ghost\' ?> sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <span>👤</span> Mon Profil
                </a>
            </div>';

foreach ($files as $f) {
    $path = $directory . '/' . $f;
    $content = file_get_contents($path);

    // PCRE regex to find the elements between <nav ... and </aside>
    $pattern = '/<nav\b[^>]*>.*?(?=<\/aside>)/s';
    $new_content = preg_replace($pattern, $template . "\n        ", $content);

    file_put_contents($path, $new_content);
}
echo "Done replacing sidebar in PC!\n";
