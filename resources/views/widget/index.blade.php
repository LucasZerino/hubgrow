<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat Widget</title>
    <style>
        :root {
            --primary-color: #1f93ff;
            --text-color: #1f2937;
            --bg-color: #ffffff;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: transparent;
            overflow: hidden;
        }
        #app {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: flex-end;
            padding: 0; 
            box-sizing: border-box;
        }
        /* Launcher (Bubble) */
        .launcher {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s ease, opacity 0.3s ease;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        .launcher:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        .launcher svg {
            width: 28px;
            height: 28px;
            fill: white;
            transition: transform 0.3s ease;
        }
        .launcher.open svg {
            transform: rotate(90deg);
            opacity: 0;
            display: none;
        }
        .launcher .close-icon {
            display: none;
            width: 24px;
            height: 24px;
        }
        .launcher.open .close-icon {
            display: block;
            transform: rotate(0);
            opacity: 1;
        }

        /* Chat Window */
        .chat-window {
            position: fixed;
            bottom: 90px; /* Space for launcher */
            right: 20px;
            width: 360px;
            height: calc(100% - 110px);
            max-height: 600px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 5px 40px rgba(0,0,0,0.16);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.19, 1, 0.22, 1);
            z-index: 999;
        }
        .chat-window.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }

        /* Header */
        .header {
            padding: 20px 24px;
            background: var(--primary-color);
            color: white;
            flex-shrink: 0;
        }
        .header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.4;
        }
        .header p {
            margin: 4px 0 0;
            font-size: 13px;
            opacity: 0.9;
            line-height: 1.4;
        }

        /* Messages Area */
        .messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f9fafb;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .message-bubble {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
            position: relative;
            word-wrap: break-word;
        }
        .message-system {
            align-self: center;
            background: var(--gray-200);
            color: var(--text-color);
            font-size: 12px;
            padding: 8px 12px;
            border-radius: 20px;
        }
        .message-agent {
            align-self: flex-start;
            background: white;
            color: var(--text-color);
            border-bottom-left-radius: 2px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .message-user {
            align-self: flex-end;
            background: var(--primary-color);
            color: white;
            border-bottom-right-radius: 2px;
        }

        /* Pre-chat Form */
        .pre-chat-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 24px;
            background: white;
            height: 100%;
            overflow-y: auto;
        }
        .pre-chat-intro {
            margin-bottom: 8px;
            color: var(--gray-500);
            font-size: 14px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
        }
        .form-group input {
            padding: 12px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            border-color: var(--primary-color);
        }
        .start-chat-btn {
            margin-top: 8px;
            padding: 14px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .start-chat-btn:hover {
            opacity: 0.9;
        }

        /* Input Area */
        .input-area {
            padding: 16px;
            background: white;
            border-top: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .input-area input {
            flex: 1;
            padding: 12px;
            border: 1px solid transparent;
            background: var(--gray-100);
            border-radius: 24px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }
        .input-area input:focus {
            background: white;
            border-color: var(--gray-200);
            box-shadow: 0 0 0 2px rgba(31, 147, 255, 0.1);
        }
        .send-btn {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .send-btn:hover {
            background: var(--gray-100);
        }
        .send-btn svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        /* Mobile Responsive */
        @media (max-width: 450px) {
            .chat-window {
                width: 100%;
                height: 100%;
                bottom: 0;
                right: 0;
                border-radius: 0;
                max-height: none;
            }
            .launcher {
                display: none; /* Hide launcher when open on mobile (handled by JS) */
            }
            .chat-window.open + .launcher {
                display: none;
            }
            /* Close button for mobile inside header */
            .mobile-close {
                display: block;
            }
        }
        .mobile-close {
            display: none;
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            cursor: pointer;
            background: none;
            border: none;
        }
    </style>
</head>
<body>
    <div id="app">
        <div class="chat-window" id="chatWindow">
            <div class="header" id="header">
                <button class="mobile-close" onclick="toggleChat()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
                <h2 id="welcomeTitle">Chat with us</h2>
                <p id="welcomeTagline">We are online</p>
            </div>
            
            <!-- Pre-Chat Form -->
            <div id="preChatForm" class="pre-chat-form" style="display: none;">
                <div class="pre-chat-intro" id="preChatIntro">Please fill in your details to start chatting with us.</div>
                <div class="form-group">
                    <label for="nameInput">Full Name</label>
                    <input type="text" id="nameInput" placeholder="e.g. John Doe">
                </div>
                <div class="form-group">
                    <label for="emailInput">Email Address</label>
                    <input type="email" id="emailInput" placeholder="e.g. john@example.com">
                </div>
                <button class="start-chat-btn" onclick="startChat()">Start Conversation</button>
            </div>

            <!-- Conversation View -->
            <div class="messages" id="messages" style="display: none;">
                <div class="message-system">Today</div>
                <div class="message-agent" id="welcomeMessageBubble">
                    Hello! How can we help you today?
                </div>
            </div>

            <div class="input-area" id="inputArea" style="display: none;">
                <input type="text" id="messageInput" placeholder="Type your message..." onkeypress="handleKeyPress(event)">
                <button class="send-btn" onclick="sendMessage()">
                    <svg viewBox="0 0 24 24">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div class="launcher" id="launcher" onclick="toggleChat()">
            <!-- Chat Icon -->
            <svg class="chat-icon" viewBox="0 0 24 24">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"></path>
            </svg>
            <!-- Close Icon -->
            <svg class="close-icon" viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path>
            </svg>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const websiteToken = urlParams.get('website_token');
        // Use relative path if on same domain, otherwise use backend URL
        // For the widget, we assume it's served from the backend, so relative is fine
        const baseUrl = window.location.origin;
        
        let config = {};
        let isOpen = false;

        async function init() {
            if (!websiteToken) {
                console.error('[Widget] No website token provided');
                return;
            }
            
            try {
                const response = await fetch(`${baseUrl}/api/public/v1/widget/config?website_token=${websiteToken}`);
                if (!response.ok) throw new Error('Failed to load config');
                
                config = await response.json();
                applyConfig(config);
            } catch (error) {
                console.error('[Widget] Error loading config:', error);
            }
        }

        function applyConfig(config) {
            const primaryColor = config.widget_color || '#1f93ff';
            document.documentElement.style.setProperty('--primary-color', primaryColor);

            if (config.welcome_title) {
                document.getElementById('welcomeTitle').innerText = config.welcome_title;
            }
            if (config.welcome_tagline) {
                document.getElementById('welcomeTagline').innerText = config.welcome_tagline;
            }
            if (config.greeting_message) {
                document.getElementById('welcomeMessageBubble').innerText = config.greeting_message;
            }

            // Handle Pre-chat form
            const preChatEnabled = config.pre_chat_form_enabled;
            if (preChatEnabled) {
                document.getElementById('preChatForm').style.display = 'flex';
                document.getElementById('messages').style.display = 'none';
                document.getElementById('inputArea').style.display = 'none';
                
                if (config.pre_chat_form_options?.pre_chat_message) {
                    document.getElementById('preChatIntro').innerText = config.pre_chat_form_options.pre_chat_message;
                }
            } else {
                showConversation();
            }
        }

        function toggleChat() {
            isOpen = !isOpen;
            const chatWindow = document.getElementById('chatWindow');
            const launcher = document.getElementById('launcher');
            
            if (isOpen) {
                chatWindow.classList.add('open');
                launcher.classList.add('open');
            } else {
                chatWindow.classList.remove('open');
                launcher.classList.remove('open');
            }
            
            // Notify parent window
            window.parent.postMessage({ type: 'grow-widget:toggle', isOpen }, '*');
        }

        function startChat() {
            const name = document.getElementById('nameInput').value;
            const email = document.getElementById('emailInput').value;
            
            if (config.pre_chat_form_options?.require_name && !name) {
                alert('Please enter your name');
                return;
            }
            if (config.pre_chat_form_options?.require_email && !email) {
                alert('Please enter your email');
                return;
            }
            
            // TODO: Send contact info to backend
            window.contactInfo = { name, email };
            showConversation();
        }

        function showConversation() {
            document.getElementById('preChatForm').style.display = 'none';
            document.getElementById('messages').style.display = 'flex';
            document.getElementById('inputArea').style.display = 'flex';
            
            // Focus input
            setTimeout(() => document.getElementById('messageInput').focus(), 100);
        }

        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }

        function sendMessage() {
            const input = document.getElementById('messageInput');
            const content = input.value.trim();
            
            if (!content) return;
            
            // Add user message to UI
            addMessage(content, 'user');
            input.value = '';
            
            // TODO: Send message to backend API
            // fetch(`${baseUrl}/api/public/v1/widget/messages`, ...)
            
            // Simulate agent reply
            setTimeout(() => {
                addMessage('Thanks for your message! We will get back to you shortly.', 'agent');
            }, 1000);
        }

        function addMessage(content, type) {
            const messagesDiv = document.getElementById('messages');
            const bubble = document.createElement('div');
            bubble.className = `message-bubble message-${type}`;
            bubble.innerText = content;
            messagesDiv.appendChild(bubble);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
        
        // Initialize
        init();
    </script>
</body>
</html>
