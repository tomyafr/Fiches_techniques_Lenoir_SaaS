<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(['technicien', 'admin']);

$isAdmin = ($_SESSION['role'] === 'admin');
$dashboardUrl = $isAdmin ? 'admin.php' : 'technicien.php';
$userName = htmlspecialchars($_SESSION['user_prenom'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Expert IA | LM Expert</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <?php renderSentryJS(); ?>
    <script>
        if (localStorage.getItem('theme') === 'light') document.documentElement.classList.add('light-mode');
    </script>
    <style>
        /* ═══ CHAT PAGE LAYOUT ═══ */
        .chat-page-wrapper {
            display: flex;
            flex-direction: column;
            height: calc(100vh - var(--mobile-header-height, 0px));
            max-height: 100%;
            overflow: hidden;
        }
        @media (min-width: 1025px) {
            .chat-page-wrapper {
                height: 100vh;
            }
        }

        /* Chat header card */
        .chat-hero {
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
            background: rgba(244, 130, 32, 0.03);
        }
        .chat-hero-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .chat-hero-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .chat-hero-info h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
        }
        .chat-hero-info p {
            margin: 0.15rem 0 0;
            font-size: 0.75rem;
            color: var(--text-dim);
        }
        .chat-hero-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.65rem;
            color: var(--success);
            font-weight: 600;
        }
        .chat-hero-status::before {
            content: '';
            width: 7px;
            height: 7px;
            background: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 6px var(--success);
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* Messages area */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            scroll-behavior: smooth;
        }
        .chat-messages::-webkit-scrollbar {
            width: 5px;
        }
        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 3px;
        }

        /* Message bubbles */
        .chat-msg {
            max-width: 75%;
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 0.88rem;
            line-height: 1.6;
            word-wrap: break-word;
            animation: msg-appear 0.35s ease forwards;
            opacity: 0;
        }
        @keyframes msg-appear {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .chat-msg.assistant {
            background: rgba(244, 130, 32, 0.08);
            border: 1px solid rgba(244, 130, 32, 0.12);
            color: var(--text-main);
            border-bottom-left-radius: 4px;
            align-self: flex-start;
        }
        .chat-msg.user {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.12), rgba(99, 102, 241, 0.08));
            border: 1px solid rgba(14, 165, 233, 0.15);
            color: var(--text-main);
            border-bottom-right-radius: 4px;
            align-self: flex-end;
        }
        .chat-msg.typing {
            background: rgba(244, 130, 32, 0.05);
            border: 1px solid rgba(244, 130, 32, 0.08);
            align-self: flex-start;
            padding: 14px 20px;
        }
        .chat-msg strong {
            color: var(--primary);
        }

        /* Typing dots */
        .typing-dots {
            display: flex;
            gap: 5px;
        }
        .typing-dots span {
            width: 8px;
            height: 8px;
            background: #F48220;
            border-radius: 50%;
            animation: bounce-dot 1.4s infinite ease-in-out;
        }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounce-dot {
            0%, 80%, 100% { transform: scale(0.5); opacity: 0.3; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* Quick suggestions */
        .chat-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 0 2rem 1rem;
            flex-shrink: 0;
        }
        .chat-suggestion-btn {
            background: rgba(244, 130, 32, 0.06);
            border: 1px solid rgba(244, 130, 32, 0.12);
            border-radius: 24px;
            padding: 8px 16px;
            font-size: 0.78rem;
            color: var(--primary);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            font-weight: 500;
        }
        .chat-suggestion-btn:hover {
            background: rgba(244, 130, 32, 0.12);
            border-color: rgba(244, 130, 32, 0.25);
            transform: translateY(-1px);
        }

        /* Input area */
        .chat-input-area {
            padding: 1rem 1.5rem 1.25rem;
            border-top: 1px solid var(--glass-border);
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.1);
        }
        .chat-input {
            flex: 1;
            background: var(--input-bg, rgba(255,255,255,0.05));
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 12px 16px;
            color: var(--text-main);
            font-size: 0.88rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            resize: none;
            max-height: 120px;
            line-height: 1.4;
        }
        .chat-input::placeholder {
            color: var(--text-dim);
        }
        .chat-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(244, 130, 32, 0.1);
        }
        .chat-send-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #F48220, #d35400);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(244, 130, 32, 0.3);
        }
        .chat-send-btn:hover { transform: scale(1.05); box-shadow: 0 6px 16px rgba(244, 130, 32, 0.4); }
        .chat-send-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }
        .chat-send-btn svg { width: 20px; height: 20px; fill: white; }

        /* Light mode */
        .light-mode .chat-msg.assistant {
            background: rgba(244, 130, 32, 0.06);
            border-color: rgba(244, 130, 32, 0.1);
            color: #333;
        }
        .light-mode .chat-msg.user {
            background: rgba(14, 165, 233, 0.06);
            border-color: rgba(14, 165, 233, 0.1);
            color: #333;
        }
        .light-mode .chat-input {
            background: #f8f9fa;
            border-color: #e0e0e0;
            color: #333;
        }
        .light-mode .chat-input-area {
            background: rgba(0, 0, 0, 0.02);
        }
        .light-mode .chat-hero {
            background: rgba(244, 130, 32, 0.02);
        }

        /* Status Dots (Pastilles) */
        .status-dot {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin: 0 4px;
            vertical-align: middle;
            box-shadow: inset 0 -2px 4px rgba(0,0,0,0.2), 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
        }
        .status-dot::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 3px;
            width: 4px;
            height: 4px;
            background: rgba(255,255,255,0.4);
            border-radius: 50%;
        }
        .dot-green { background: #22c55e; box-shadow: 0 0 8px rgba(34, 197, 94, 0.4); }
        .dot-orange { background: #f97316; box-shadow: 0 0 8px rgba(249, 115, 22, 0.4); }
        .dot-red { background: #ef4444; box-shadow: 0 0 8px rgba(239, 68, 68, 0.4); }
        .dot-darkred { background: #7f1d1d; box-shadow: 0 0 8px rgba(127, 29, 29, 0.4); }
        .dot-gray { background: #94a3b8; box-shadow: 0 0 8px rgba(148, 163, 184, 0.4); }

        /* Mobile responsive */
        @media (max-width: 1024px) {
            .chat-hero { padding: 1rem 1.25rem; }
            .chat-messages { padding: 1rem 1.25rem; }
            .chat-suggestions { padding: 0 1.25rem 0.75rem; }
            .chat-input-area { padding: 0.75rem 1rem 1rem; }
            .chat-msg { max-width: 88%; font-size: 0.85rem; }
            .chat-hero-avatar { width: 44px; height: 44px; font-size: 22px; border-radius: 12px; }
            .chat-hero-info h2 { font-size: 1rem; }
            .main-content {
                padding: 0 !important;
                padding-top: var(--mobile-header-height) !important;
                padding-bottom: 70px !important;
            }
        }
    </style>
</head>

<body onload="document.body.classList.add('loaded')">
    <header class="mobile-header">
        <button class="mobile-logo-btn" onclick="toggleSidebar()" aria-label="Menu">
            <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir" class="mobile-header-logo">
        </button>
        <span class="mobile-header-title">Expert IA</span>
        <span class="mobile-header-user">
            <?php if (!empty($_SESSION['avatar'])): ?>
                <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                    style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
            <?php else: ?>
                <?= $userName ?>
            <?php endif; ?>
        </span>
    </header>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close-btn" onclick="toggleSidebar()">&times;</button>
            <div style="margin-bottom: 2.5rem;">
                <a href="<?= $dashboardUrl ?>" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;">
                    <img src="/assets/lenoir_logo_trans.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">
                    <?= $isAdmin ? 'Administrateur' : 'Espace Technicien' ?>
                </p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <a href="<?= $dashboardUrl ?>" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_dashboard_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Tableau de bord
                </a>
                <?php if ($isAdmin): ?>
                <a href="admin.php?new=1#" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                </a>
                <?php else: ?>
                <a href="technicien.php?new=1#" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_add_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Nouveau Rapport
                </a>
                <?php endif; ?>
                <a href="historique.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_history_white.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Historique
                </a>
                <?php if ($isAdmin): ?>
                <a href="equipe.php" class="btn btn-ghost sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_team_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Équipe
                </a>
                <?php endif; ?>
                <a href="assistant.php" class="btn btn-primary sidebar-link"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/ai_expert.jpg" style="height: 18px; width: 18px; margin-right: 8px; margin-left: -2px; mix-blend-mode: screen;"> Expert IA
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase;">Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <div style="width: 42px; height: 42px; border-radius: 50%; border: 1.5px solid rgba(255, 255, 255, 0.8); overflow: hidden; flex-shrink: 0; box-shadow: 0 0 10px rgba(0,0,0,0.2);">
                            <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php endif; ?>
                    <p style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                </div>

                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; color: var(--error); margin-bottom: 0.4rem;">
                    <img src="/assets/icon_logout_red.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Se déconnecter
                </a>
                <a href="profile.php" class="btn btn-ghost sidebar-link"
                    style="width: 100%; justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem;">
                    <img src="/assets/icon_profile_blue.svg" style="height: 16px; width: 16px; margin-right: 8px;"> Mon Profil
                </a>
            </div>
        </aside>

        <main class="main-content" style="padding: 0; display: flex; flex-direction: column; height: 100vh;">
            <div class="chat-page-wrapper">
                <!-- Header -->
                <div class="chat-hero">
                    <div class="chat-hero-avatar">
                        <img src="/assets/ai_expert.jpg" alt="Expert IA" style="mix-blend-mode: screen;">
                    </div>
                    <div class="chat-hero-info">
                        <h2>Expert IA <span style="font-weight: 400; color: var(--text-dim); font-size: 0.85rem;">· Support Intelligence Artificielle</span></h2>
                        <p><span class="chat-hero-status">En ligne</span> · Prêt à t'aider sur l'application</p>
                    </div>
                </div>

                <!-- Messages -->
                <div class="chat-messages" id="chatMessages">
                    <div class="chat-msg assistant" style="animation-delay: 0.1s;">
                        Salut <?= $userName ?> ! 👋 Je suis ton <strong>Expert IA</strong>. Je connais l'application LM Expert sur le bout des doigts. Pose-moi ta question !
                    </div>
                </div>

                <!-- Quick suggestions -->
                <div class="chat-suggestions" id="chatSuggestions">
                    <button class="chat-suggestion-btn" onclick="sendQuick('Comment créer une nouvelle intervention ?')">📋 Nouvelle intervention</button>
                    <button class="chat-suggestion-btn" onclick="sendQuick('Comment ajouter des photos sur une fiche ?')">📸 Ajouter des photos</button>
                    <button class="chat-suggestion-btn" onclick="sendQuick('Comment télécharger le rapport PDF ?')">📄 Rapport PDF</button>
                    <button class="chat-suggestion-btn" onclick="sendQuick('À quoi servent les pastilles de couleur ?')">🔵 Les pastilles</button>
                    <button class="chat-suggestion-btn" onclick="sendQuick('Comment fonctionne le texte généré par l\'IA ?')">🤖 Texte IA</button>
                    <button class="chat-suggestion-btn" onclick="sendQuick('Comment terminer et signer une intervention ?')">✍️ Signature</button>
                </div>

                <!-- Input -->
                <div class="chat-input-area">
                    <textarea class="chat-input" id="chatInput" placeholder="Écris ta question ici..." rows="1" onkeydown="handleKey(event)"></textarea>
                    <button class="chat-send-btn" id="chatSendBtn" onclick="sendMessage()">
                        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mobile-bottom-nav">
        <div class="mobile-bottom-nav-inner">
            <a href="<?= $dashboardUrl ?>" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_dashboard_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Tableau</span>
            </a>
            <a href="historique.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_history_white.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Historique</span>
            </a>
            <a href="assistant.php" class="mobile-nav-item active" style="color: inherit; text-decoration:none;">
                <div style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <img src="/assets/ai_expert.jpg" style="height: 22px; width: 22px; object-fit: cover; mix-blend-mode: screen;">
                </div>
                <span class="mobile-nav-label">Expert IA</span>
            </a>
            <a href="profile.php" class="mobile-nav-item" style="color: inherit; text-decoration:none;">
                <img src="/assets/icon_profile_blue.svg" style="height: 24px; width: 24px; margin-bottom: 4px; opacity: 0.7;">
                <span class="mobile-nav-label">Profil</span>
            </a>
        </div>
    </nav>

    <script>
        let isSending = false;

        function handleKey(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        function sendQuick(text) {
            document.getElementById('chatInput').value = text;
            document.getElementById('chatSuggestions').style.display = 'none';
            sendMessage();
        }

        async function sendMessage() {
            if (isSending) return;
            const input = document.getElementById('chatInput');
            const text = input.value.trim();
            if (!text) return;

            isSending = true;
            document.getElementById('chatSendBtn').disabled = true;
            input.value = '';
            input.style.height = 'auto';

            addMessage(text, 'user');
            const typingEl = showTyping();

            try {
                const res = await fetch('/assistant_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text })
                });
                const data = await res.json();
                typingEl.remove();

                if (data.success && data.reply) {
                    addMessage(data.reply, 'assistant');
                } else {
                    if (data.debug) console.error('Expert IA Error:', data.debug);
                    let errMsg = data.error || 'Désolé, je n\'ai pas pu répondre. Réessaie dans un instant ! 🔄';
                    if (data.debug && data.debug.includes('HTTP 0')) errMsg += ' (Délai d\'attente dépassé)';
                    addMessage(errMsg, 'assistant');
                }
            } catch (err) {
                typingEl.remove();
                addMessage('Oups, une erreur est survenue. Vérifie ta connexion et réessaie ! 🔄', 'assistant');
            }

            isSending = false;
            document.getElementById('chatSendBtn').disabled = false;
            input.focus();
        }

        function addMessage(text, role) {
            const container = document.getElementById('chatMessages');
            const div = document.createElement('div');
            div.className = 'chat-msg ' + role;
            
            // Render Markdown-ish bold and newlines
            let html = text
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>');

            // Replace emojis with beautiful CSS dots
            html = html.replace(/🟢/g, '<span class="status-dot dot-green"></span>');
            html = html.replace(/🟠/g, '<span class="status-dot dot-orange"></span>');
            html = html.replace(/🔴/g, '<span class="status-dot dot-red"></span>');
            html = html.replace(/⬛/g, '<span class="status-dot dot-darkred"></span>');
            html = html.replace(/⚪/g, '<span class="status-dot dot-gray"></span>');

            div.innerHTML = html;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
        }

        function showTyping() {
            const container = document.getElementById('chatMessages');
            const div = document.createElement('div');
            div.className = 'chat-msg typing';
            div.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            return div;
        }

        // Auto-resize textarea
        const textarea = document.getElementById('chatInput');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
        }

        // Auto-focus on desktop
        if (window.innerWidth > 1024) {
            document.getElementById('chatInput').focus();
        }
    </script>
</body>

</html>
