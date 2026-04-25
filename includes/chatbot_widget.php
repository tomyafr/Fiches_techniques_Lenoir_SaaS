<?php
/**
 * Tom - Assistant Virtuel LM Expert
 * Widget chatbot flottant à inclure sur chaque page.
 */
?>
<!-- Tom Assistant Chatbot Widget -->
<style>
    /* === CHATBOT TOGGLE BUTTON === */
    .tom-chat-toggle {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #F48220, #e65100);
        border: none;
        cursor: pointer;
        z-index: 9998;
        box-shadow: 0 4px 20px rgba(244, 130, 32, 0.4), 0 0 0 0 rgba(244, 130, 32, 0.3);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        justify-content: center;
        animation: tom-pulse 3s infinite ease-in-out;
    }
    .tom-chat-toggle:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 25px rgba(244, 130, 32, 0.5);
    }
    .tom-chat-toggle.open {
        animation: none;
        background: linear-gradient(135deg, #555, #333);
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }
    .tom-chat-toggle svg {
        width: 26px;
        height: 26px;
        fill: white;
        transition: transform 0.3s ease;
    }
    .tom-chat-toggle.open svg.tom-icon-robot { display: none; }
    .tom-chat-toggle svg.tom-icon-close { display: none; }
    .tom-chat-toggle.open svg.tom-icon-close { display: block; }

    @keyframes tom-pulse {
        0%, 100% { box-shadow: 0 4px 20px rgba(244, 130, 32, 0.4), 0 0 0 0 rgba(244, 130, 32, 0.25); }
        50% { box-shadow: 0 4px 20px rgba(244, 130, 32, 0.4), 0 0 0 10px rgba(244, 130, 32, 0); }
    }

    /* === CHATBOT CONTAINER === */
    .tom-chat-container {
        position: fixed;
        bottom: 90px;
        right: 24px;
        width: 380px;
        max-height: 520px;
        background: var(--card-bg, #1a1a2e);
        border: 1px solid var(--glass-border, rgba(255,255,255,0.08));
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4), 0 0 1px rgba(255,255,255,0.05);
        z-index: 9999;
        display: none;
        flex-direction: column;
        overflow: hidden;
        opacity: 0;
        transform: translateY(20px) scale(0.95);
        transition: opacity 0.3s ease, transform 0.3s ease;
    }
    .tom-chat-container.visible {
        display: flex;
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    /* Header */
    .tom-chat-header {
        background: linear-gradient(135deg, #F48220, #d35400);
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }
    .tom-chat-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }
    .tom-chat-header-info h4 {
        margin: 0;
        font-size: 0.9rem;
        color: white;
        font-weight: 700;
    }
    .tom-chat-header-info p {
        margin: 0;
        font-size: 0.65rem;
        color: rgba(255,255,255,0.7);
    }

    /* Messages */
    .tom-chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 280px;
        max-height: 340px;
        scroll-behavior: smooth;
    }
    .tom-chat-messages::-webkit-scrollbar {
        width: 4px;
    }
    .tom-chat-messages::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.1);
        border-radius: 2px;
    }
    .tom-msg {
        max-width: 85%;
        padding: 10px 14px;
        border-radius: 14px;
        font-size: 0.82rem;
        line-height: 1.5;
        word-wrap: break-word;
        animation: tom-msg-in 0.3s ease;
    }
    @keyframes tom-msg-in {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .tom-msg.assistant {
        background: rgba(244, 130, 32, 0.12);
        color: var(--text-primary, #e2e8f0);
        border-bottom-left-radius: 4px;
        align-self: flex-start;
    }
    .tom-msg.user {
        background: rgba(59, 130, 246, 0.15);
        color: var(--text-primary, #e2e8f0);
        border-bottom-right-radius: 4px;
        align-self: flex-end;
    }
    .tom-msg.typing {
        background: rgba(244, 130, 32, 0.08);
        align-self: flex-start;
    }
    .tom-typing-dots {
        display: flex;
        gap: 5px;
        padding: 4px 0;
    }
    .tom-typing-dots span {
        width: 7px;
        height: 7px;
        background: #F48220;
        border-radius: 50%;
        animation: tom-bounce 1.4s infinite ease-in-out;
    }
    .tom-typing-dots span:nth-child(2) { animation-delay: 0.2s; }
    .tom-typing-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes tom-bounce {
        0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
        40% { transform: scale(1); opacity: 1; }
    }

    /* Input */
    .tom-chat-input-area {
        padding: 12px 14px;
        border-top: 1px solid var(--glass-border, rgba(255,255,255,0.08));
        display: flex;
        gap: 8px;
        flex-shrink: 0;
        background: rgba(0,0,0,0.15);
    }
    .tom-chat-input {
        flex: 1;
        background: var(--input-bg, rgba(255,255,255,0.05));
        border: 1px solid var(--glass-border, rgba(255,255,255,0.1));
        border-radius: 10px;
        padding: 10px 14px;
        color: var(--text-primary, #e2e8f0);
        font-size: 0.82rem;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s;
        resize: none;
        max-height: 80px;
    }
    .tom-chat-input::placeholder {
        color: var(--text-dim, rgba(255,255,255,0.3));
    }
    .tom-chat-input:focus {
        border-color: #F48220;
    }
    .tom-chat-send {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: linear-gradient(135deg, #F48220, #d35400);
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.2s;
        align-self: flex-end;
    }
    .tom-chat-send:hover { transform: scale(1.05); }
    .tom-chat-send:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
    .tom-chat-send svg { width: 18px; height: 18px; fill: white; }

    /* Quick actions */
    .tom-quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 0 16px 12px;
    }
    .tom-quick-btn {
        background: rgba(244, 130, 32, 0.08);
        border: 1px solid rgba(244, 130, 32, 0.15);
        border-radius: 20px;
        padding: 5px 12px;
        font-size: 0.7rem;
        color: #F48220;
        cursor: pointer;
        transition: all 0.2s;
        font-family: inherit;
    }
    .tom-quick-btn:hover {
        background: rgba(244, 130, 32, 0.15);
        border-color: rgba(244, 130, 32, 0.3);
    }

    /* Light mode support */
    .light-mode .tom-chat-container {
        background: #ffffff;
        border-color: #e0e0e0;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    }
    .light-mode .tom-msg.assistant {
        background: rgba(244, 130, 32, 0.08);
        color: #333;
    }
    .light-mode .tom-msg.user {
        background: rgba(59, 130, 246, 0.08);
        color: #333;
    }
    .light-mode .tom-chat-input {
        background: #f5f5f5;
        border-color: #ddd;
        color: #333;
    }
    .light-mode .tom-chat-input-area {
        background: rgba(0,0,0,0.03);
    }
    .light-mode .tom-quick-btn {
        color: #d35400;
    }

    /* Mobile responsive */
    @media (max-width: 500px) {
        .tom-chat-container {
            right: 8px;
            left: 8px;
            bottom: 80px;
            width: auto;
            max-height: 70vh;
        }
        .tom-chat-toggle {
            bottom: 16px;
            right: 16px;
        }
    }
</style>

<!-- Toggle Button -->
<button class="tom-chat-toggle" id="tomChatToggle" onclick="tomToggleChat()" title="Assistant Tom">
    <svg class="tom-icon-robot" viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1H2a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h1a7 7 0 0 1 7-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 0 1 2-2M7.5 13A2.5 2.5 0 0 0 5 15.5 2.5 2.5 0 0 0 7.5 18a2.5 2.5 0 0 0 2.5-2.5A2.5 2.5 0 0 0 7.5 13m9 0a2.5 2.5 0 0 0-2.5 2.5 2.5 2.5 0 0 0 2.5 2.5 2.5 2.5 0 0 0 2.5-2.5 2.5 2.5 0 0 0-2.5-2.5Z"/></svg>
    <svg class="tom-icon-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
</button>

<!-- Chat Container -->
<div class="tom-chat-container" id="tomChatContainer">
    <div class="tom-chat-header">
        <div class="tom-chat-avatar">🤖</div>
        <div class="tom-chat-header-info">
            <h4>Tom · Assistant LM Expert</h4>
            <p>En ligne · Prêt à t'aider</p>
        </div>
    </div>

    <div class="tom-chat-messages" id="tomChatMessages">
        <div class="tom-msg assistant">
            Salut <?= htmlspecialchars($_SESSION['user_prenom'] ?? '') ?> ! 👋 Je suis Tom, ton assistant virtuel. Comment je peux t'aider aujourd'hui ?
        </div>
    </div>

    <div class="tom-quick-actions" id="tomQuickActions">
        <button class="tom-quick-btn" onclick="tomSendQuick('Comment créer une nouvelle intervention ?')">📋 Nouvelle intervention</button>
        <button class="tom-quick-btn" onclick="tomSendQuick('Comment ajouter des photos ?')">📸 Ajouter des photos</button>
        <button class="tom-quick-btn" onclick="tomSendQuick('Comment télécharger le rapport PDF ?')">📄 Rapport PDF</button>
        <button class="tom-quick-btn" onclick="tomSendQuick('À quoi servent les pastilles de couleur ?')">🔵 Pastilles</button>
    </div>

    <div class="tom-chat-input-area">
        <textarea class="tom-chat-input" id="tomChatInput" placeholder="Écris ta question..." rows="1" onkeydown="tomHandleKey(event)"></textarea>
        <button class="tom-chat-send" id="tomChatSend" onclick="tomSendMessage()">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </div>
</div>

<script>
(function() {
    let tomIsOpen = false;
    let tomIsSending = false;

    window.tomToggleChat = function() {
        const container = document.getElementById('tomChatContainer');
        const toggle = document.getElementById('tomChatToggle');
        tomIsOpen = !tomIsOpen;

        if (tomIsOpen) {
            container.style.display = 'flex';
            toggle.classList.add('open');
            // Force reflow before adding visible class for animation
            container.offsetHeight;
            container.classList.add('visible');
            document.getElementById('tomChatInput').focus();
        } else {
            container.classList.remove('visible');
            toggle.classList.remove('open');
            setTimeout(() => { container.style.display = 'none'; }, 300);
        }
    };

    window.tomHandleKey = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            tomSendMessage();
        }
    };

    window.tomSendQuick = function(text) {
        document.getElementById('tomChatInput').value = text;
        // Hide quick actions after first use
        document.getElementById('tomQuickActions').style.display = 'none';
        tomSendMessage();
    };

    window.tomSendMessage = async function() {
        if (tomIsSending) return;

        const input = document.getElementById('tomChatInput');
        const text = input.value.trim();
        if (!text) return;

        tomIsSending = true;
        document.getElementById('tomChatSend').disabled = true;
        input.value = '';

        // Add user message
        tomAddMessage(text, 'user');

        // Show typing indicator
        const typingId = tomShowTyping();

        try {
            const res = await fetch('/api/assistant_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            const data = await res.json();

            tomRemoveTyping(typingId);

            if (data.success && data.reply) {
                tomAddMessage(data.reply, 'assistant');
            } else {
                tomAddMessage(data.error || 'Désolé, je n\'ai pas pu répondre. Réessaie ! 🔄', 'assistant');
            }
        } catch (err) {
            tomRemoveTyping(typingId);
            tomAddMessage('Oups, une erreur est survenue. Vérifie ta connexion et réessaie ! 🔄', 'assistant');
        }

        tomIsSending = false;
        document.getElementById('tomChatSend').disabled = false;
        input.focus();
    };

    function tomAddMessage(text, role) {
        const container = document.getElementById('tomChatMessages');
        const div = document.createElement('div');
        div.className = 'tom-msg ' + role;
        // Convert markdown-like bold to HTML
        div.innerHTML = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }

    let typingCounter = 0;
    function tomShowTyping() {
        const container = document.getElementById('tomChatMessages');
        const id = 'tom-typing-' + (++typingCounter);
        const div = document.createElement('div');
        div.className = 'tom-msg typing';
        div.id = id;
        div.innerHTML = '<div class="tom-typing-dots"><span></span><span></span><span></span></div>';
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        return id;
    }

    function tomRemoveTyping(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    // Auto-resize textarea
    const textarea = document.getElementById('tomChatInput');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 80) + 'px';
        });
    }
})();
</script>
