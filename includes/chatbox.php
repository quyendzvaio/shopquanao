<?php
/**
 * Chatbot widget — embed trong footer.php
 * Tự động inject session token, user token (nếu đã đăng nhập)
 */
$chatToken = $_SESSION['chat_token'] ?? '';
if (!$chatToken) {
    $chatToken = bin2hex(random_bytes(16));
    $_SESSION['chat_token'] = $chatToken;
}
$userToken = $_SESSION['api_token'] ?? '';
$isLoggedIn = isset($_SESSION['user_id']);
$username  = $_SESSION['username'] ?? '';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
#chatbot-toggle {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #000;
    color: #fff;
    border: none;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    z-index: 9999;
    transition: transform 0.2s;
}
#chatbot-toggle:hover { transform: scale(1.1); }
#chatbot-toggle.active { background: #333; }

#chatbot-box {
    position: fixed;
    bottom: 90px;
    right: 24px;
    width: 380px;
    max-height: 600px;
    height: 75vh;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.2);
    z-index: 9998;
    display: none;
    flex-direction: column;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
#chatbot-box.open { display: flex; }

.chat-header {
    background: #000;
    color: #fff;
    padding: 16px 20px;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 16px 16px 0 0;
}
.chat-header i { font-size: 20px; }
.chat-header .chat-status {
    font-size: 11px;
    font-weight: 400;
    opacity: 0.8;
    margin-left: auto;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #f8f8f8;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.chat-msg {
    max-width: 85%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.chat-msg.bot {
    background: #e9ecef;
    color: #1a1a1a;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
}
.chat-msg.user {
    background: #000;
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}
.chat-msg.system {
    background: #fff3cd;
    color: #856404;
    align-self: center;
    text-align: center;
    font-size: 12px;
    border-radius: 8px;
    max-width: 95%;
}

.chat-input-area {
    padding: 12px 16px;
    border-top: 1px solid #eee;
    display: flex;
    gap: 8px;
    background: #fff;
}
.chat-input-area input {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 24px;
    padding: 10px 16px;
    font-size: 14px;
    outline: none;
}
.chat-input-area input:focus { border-color: #000; }
.chat-input-area button {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #000;
    color: #fff;
    border: none;
    cursor: pointer;
    font-size: 16px;
}
.chat-input-area button:hover { background: #333; }
.chat-input-area button:disabled { background: #ccc; cursor: not-allowed; }

.chat-typing {
    display: flex;
    gap: 4px;
    padding: 10px 14px;
    background: #e9ecef;
    border-radius: 14px;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
}
.chat-typing span {
    width: 8px;
    height: 8px;
    background: #888;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}
.chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing {
    0%, 60%, 100% { opacity: 0.3; transform: translateY(0); }
    30% { opacity: 1; transform: translateY(-4px); }
}

.chat-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px 16px 4px;
    background: #f8f8f8;
    border-top: 1px solid #eee;
}
.chat-suggestions button {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 12px;
    border: 1px solid #ddd;
    background: #fff;
    color: #555;
    cursor: pointer;
    white-space: nowrap;
}
.chat-suggestions button:hover {
    border-color: #000;
    color: #000;
}

@media (max-width: 480px) {
    #chatbot-box {
        width: calc(100vw - 32px);
        right: 16px;
        bottom: 80px;
        height: 70vh;
    }
}
</style>

<button id="chatbot-toggle" onclick="toggleChat()" title="Chat hỗ trợ">
    <i class="fas fa-comment-dots"></i>
</button>

<div id="chatbot-box">
    <div class="chat-header">
        <i class="fas fa-robot"></i>
        Fashion Assistant
        <span class="chat-status">🟢 Online</span>
    </div>
    <div class="chat-messages" id="chat-messages"></div>
    <div class="chat-suggestions" id="chat-suggestions"></div>
    <div class="chat-input-area">
        <input type="text" id="chat-input" placeholder="Nhập tin nhắn..." onkeydown="if(event.key==='Enter') sendMessage()">
        <button id="chat-send-btn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
const API_CHAT = window.location.origin + '/api/chatbot';
var chatSessionToken = '<?= addslashes($chatToken) ?>';
const CHAT_USER_TOKEN = '<?= addslashes($userToken) ?>';
const CHAT_IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
let isLoading = false;

function sanitizeAssistantText(text) {
    return String(text || '')
        .replace(/https?:\/\/\S*\/product\.php\?id=\d+/gi, '')
        .replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu, '')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function toggleChat() {
    const box = document.getElementById('chatbot-box');
    const btn = document.getElementById('chatbot-toggle');
    const isOpen = box.classList.toggle('open');
    btn.classList.toggle('active', isOpen);
    if (isOpen) {
        setTimeout(() => document.getElementById('chat-input').focus(), 300);
        // Load history when opening chat
        loadChatHistory();
    }
}

function addMessage(role, text) {
    const container = document.getElementById('chat-messages');
    const msg = document.createElement('div');
    msg.className = 'chat-msg ' + role;
    msg.textContent = (role === 'bot' || role === 'system') ? sanitizeAssistantText(text) : text;
    container.appendChild(msg);
    container.scrollTop = container.scrollHeight;
}

function showTyping() {
    const container = document.getElementById('chat-messages');
    const div = document.createElement('div');
    div.className = 'chat-typing';
    div.id = 'chat-typing-indicator';
    div.innerHTML = '<span></span><span></span><span></span>';
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function hideTyping() {
    const el = document.getElementById('chat-typing-indicator');
    if (el) el.remove();
}

function showSuggestions() {
    const container = document.getElementById('chat-suggestions');
    container.style.display = 'flex';
    const suggestions = [
        'Áo khoác dưới 500k',
        'Áo thun giá rẻ',
        'Quần jeans nam',
        'Váy maxi',
        'Chọn size cho 1m7 65kg',
        'Phối đồ với áo thun trắng',
    ];
    container.innerHTML = '';
    suggestions.forEach(s => {
        const btn = document.createElement('button');
        btn.textContent = s;
        btn.onclick = () => { document.getElementById('chat-input').value = s; sendMessage(); };
        container.appendChild(btn);
    });
}

async function loadChatHistory() {
    // For logged-in users: load from API
    if (CHAT_USER_TOKEN) {
        try {
            const res = await fetch(API_CHAT + '/history', {
                headers: { 'Authorization': 'Bearer ' + CHAT_USER_TOKEN },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.messages || data.messages.length === 0) {
                // No history, show suggestions
                showSuggestions();
                return;
            }

            const container = document.getElementById('chat-messages');
            // Don't clear if already has messages (e.g. from this session)
            if (container.children.length > 0) return;

            container.innerHTML = '';
            document.getElementById('chat-suggestions').style.display = 'none';

            // Update session token from history
            if (data.session_token) {
                chatSessionToken = data.session_token;
            }

            data.messages.forEach(m => {
                if (m.role === 'user' || m.role === 'bot') {
                    addMessage(m.role, m.message);
                    if (m.products && m.products.length > 0) {
                        renderProductCards(m.products);
                    }
                }
            });
            setTimeout(() => { const msgs = document.getElementById('chat-messages'); msgs.scrollTop = msgs.scrollHeight; }, 100);
        } catch (e) {
            // Silent fail
        }
    } else {
        // Anonymous: show suggestions
        showSuggestions();
    }
}

async function sendMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text || isLoading) return;

    input.value = '';
    addMessage('user', text);
    isLoading = true;
    document.getElementById('chat-send-btn').disabled = true;
    showTyping();
    document.getElementById('chat-suggestions').style.display = 'none';

    try {
        const headers = { 'Content-Type': 'application/json' };
        if (CHAT_USER_TOKEN) {
            headers['Authorization'] = 'Bearer ' + CHAT_USER_TOKEN;
        }
        const res = await fetch(API_CHAT, {
            method: 'POST',
            headers,
            body: JSON.stringify({ message: text, session_token: chatSessionToken }),
        });
        const raw = await res.text();
        hideTyping();
        let data;
        try { data = JSON.parse(raw); } catch (e) { data = { error: true, message: raw.substring(0, 500) }; }
        if (data.error) {
            addMessage('system', data.message);
        } else {
            addMessage('bot', data.message);
            if (data.products && data.products.length > 0) {
                renderProductCards(data.products);
            }
            if (data.redirect_url) {
                setTimeout(() => {
                    window.location.href = data.redirect_url;
                }, 900);
            }
            // Sync session token for continuity
            if (data.session_token && data.session_token !== chatSessionToken) {
                chatSessionToken = data.session_token;
            }
        }
    } catch (e) {
        hideTyping();
        addMessage('system', 'Lỗi kết nối. Vui lòng thử lại sau.');
    }

    isLoading = false;
    document.getElementById('chat-send-btn').disabled = false;
    setTimeout(() => document.getElementById('chat-input').focus(), 100);
}

function renderProductCards(products) {
    const container = document.getElementById('chat-messages');
    const cardWrap = document.createElement('div');
    cardWrap.style.cssText = 'display:flex; flex-wrap:wrap; gap:8px; padding:4px 0; max-width:100%; align-self:flex-start;';

    products.forEach(p => {
        const imgSrc = p.image_url || ('/images/' + (p.image || ''));
        const stockLabel = p.stock > 0 ? 'Còn ' + p.stock : 'Hết hàng';
        const stockColor = p.stock > 0 ? '#27ae60' : '#e74c3c';

        const card = document.createElement('a');
        card.href = p.url;
        card.target = '_blank';
        card.style.cssText = 'display:flex; flex-direction:column; width:calc(50% - 4px); border:1px solid #eee; border-radius:8px; overflow:hidden; text-decoration:none; color:inherit; background:#fff; transition:0.2s;';

        card.innerHTML = `
            <div style="width:100%; height:110px; background:#f5f5f5; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                <img src="${imgSrc}" alt="${p.name}" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'">
            </div>
            <div style="padding:6px 8px;">
                <div style="font-size:12px; font-weight:700; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${p.name}</div>
                <div style="font-size:11px; color:#d0021b; font-weight:700; margin-top:2px;">${Number(p.price).toLocaleString()}đ</div>
                <div style="font-size:10px; color:${stockColor}; margin-top:2px;">${stockLabel}</div>
            </div>
        `;

        card.addEventListener('mouseenter', () => { card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)'; });
        card.addEventListener('mouseleave', () => { card.style.boxShadow = 'none'; });

        cardWrap.appendChild(card);
    });

    container.appendChild(cardWrap);
    cardWrap.scrollIntoView({ behavior: 'smooth', block: 'end' });
}

// Auto-load history on page load (if chat was previously opened)
document.addEventListener('DOMContentLoaded', function() {
    // Store session token reference for cross-page continuity
    const stored = sessionStorage.getItem('chat_session_token');
    if (stored) chatSessionToken = stored;
});
</script>
