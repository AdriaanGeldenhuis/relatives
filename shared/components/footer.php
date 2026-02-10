<?php
/**
 * GLOBAL FOOTER - WITH ADVANCED VOICE ASSISTANT
 */
?>
    <!-- Global Footer -->
    <footer class="global-footer" id="globalFooter">
        <style>
            /* Same styles as before */
            .main-content {
                padding-bottom: 40px;
            }

            .global-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 999;
                pointer-events: none;
            }

            .footer-container {
                max-width: 1800px;
                margin: 0 auto;
                padding: 0 20px 20px;
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
            }
            
            .footer-btn {
                pointer-events: all;
                border: none;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
                position: relative;
                flex-shrink: 0;
            }
            
            .mic-btn {
                width: 47px;
                height: 47px;
                border-radius: 50%;
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                font-size: 24px;
                box-shadow: 0 10px 40px rgba(102, 126, 234, 0.5);
            }
            
            .mic-btn:hover {
                transform: scale(1.1) translateY(-5px);
            }
            
            .mic-btn.listening {
                animation: micPulse 1.5s ease-in-out infinite;
            }

            .mic-btn.wake-listening {
                animation: wakeWordPulse 2s ease-in-out infinite;
                background: linear-gradient(135deg, #43e97b, #38f9d7);
            }
            
            @keyframes micPulse {
                0%, 100% {
                    box-shadow: 0 10px 40px rgba(102, 126, 234, 0.5);
                }
                50% {
                    box-shadow: 0 10px 60px rgba(102, 126, 234, 1), 0 0 30px rgba(102, 126, 234, 0.8);
                }
            }

            @keyframes wakeWordPulse {
                0%, 100% {
                    box-shadow: 0 10px 40px rgba(67, 233, 123, 0.5);
                    transform: scale(1);
                }
                50% {
                    box-shadow: 0 10px 60px rgba(67, 233, 123, 1), 0 0 30px rgba(67, 233, 123, 0.8);
                    transform: scale(1.05);
                }
            }

            .mic-btn.processing {
                animation: processingPulse 1s ease-in-out infinite;
                background: linear-gradient(135deg, #f093fb, #f5576c);
            }

            @keyframes processingPulse {
                0%, 100% {
                    box-shadow: 0 10px 40px rgba(240, 147, 251, 0.5);
                }
                50% {
                    box-shadow: 0 10px 60px rgba(240, 147, 251, 1), 0 0 30px rgba(240, 147, 251, 0.8);
                }
            }

            .voice-status.listening .status-icon {
                animation: statusIconPulse 1.2s ease-in-out infinite;
            }

            @keyframes statusIconPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.15); }
            }
            
            .footer-right {
                display: flex;
                align-items: flex-end;
                gap: 15px;
            }
            
            .tracking-btn {
                width: 47px;
                height: 47px;
                border-radius: 50%;
                background: linear-gradient(135deg, #f093fb, #f5576c);
                color: white;
                font-size: 24px;
                box-shadow: 0 10px 40px rgba(240, 147, 251, 0.5);
            }
            
            .tracking-btn:hover {
                transform: scale(1.1) translateY(-5px);
            }

            .help-btn-footer {
                width: 47px;
                height: 47px;
                border-radius: 50%;
                background: linear-gradient(135deg, #f093fb, #f5576c);
                color: white;
                box-shadow: 0 10px 40px rgba(240, 147, 251, 0.5);
            }

            .help-btn-footer svg {
                stroke: white;
            }

            .help-btn-footer:hover {
                transform: scale(1.1) translateY(-5px);
            }

            .plus-wrapper {
                position: relative;
            }
            
            .plus-btn {
                width: 47px;
                height: 47px;
                border-radius: 50%;
                background: linear-gradient(135deg, #43e97b, #38f9d7);
                color: white;
                font-size: 28px;
                box-shadow: 0 10px 40px rgba(67, 233, 123, 0.5);
            }
            
            .plus-btn:hover {
                transform: scale(1.1) translateY(-5px);
            }
            
            .plus-btn.active {
                transform: rotate(45deg);
            }
            
            .plus-menu {
                position: absolute;
                bottom: 65px;
                right: 0;
                display: none;
                flex-direction: column;
                gap: 12px;
                pointer-events: all;
                z-index: 1000;
            }
            
            .plus-menu.active {
                display: flex;
            }
            
            .plus-menu-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 20px;
                background: white;
                border-radius: 50px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
                cursor: pointer;
                text-decoration: none;
                color: #333;
                font-weight: 600;
                white-space: nowrap;
            }
            
            .plus-menu-item:hover {
                transform: translateX(-10px);
            }
            
            .plus-menu-icon {
                font-size: 24px;
                width: 35px;
                text-align: center;
            }
            
            .menu-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.3);
                backdrop-filter: blur(4px);
                z-index: 998;
                display: none;
                pointer-events: all;
            }
            
            .menu-backdrop.active {
                display: block;
            }
            
            .voice-modal {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.9);
                backdrop-filter: blur(20px);
                z-index: 9999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .voice-modal.active {
                display: flex;
                animation: voiceModalFadeIn 0.4s ease;
            }
            
            @keyframes voiceModalFadeIn {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }
            
            .voice-modal-content {
                background: linear-gradient(180deg, rgba(102, 126, 234, 0.95), rgba(118, 75, 162, 0.95));
                backdrop-filter: blur(40px);
                border-radius: 32px;
                padding: 60px 40px;
                max-width: 550px;
                width: 100%;
                position: relative;
                box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);
                animation: voiceModalSlideUp 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            }
            
            @keyframes voiceModalSlideUp {
                from {
                    transform: translateY(50px);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            .close-voice-modal {
                position: absolute;
                top: 20px;
                right: 20px;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.2);
                border: none;
                color: white;
                font-size: 24px;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .close-voice-modal:hover {
                background: rgba(255, 255, 255, 0.3);
                transform: rotate(90deg);
            }
            
            .voice-status {
                text-align: center;
                margin-bottom: 20px;
            }

            /* Modal Mic Button - Large tappable button */
            .modal-mic-btn {
                width: 100px;
                height: 100px;
                border-radius: 50%;
                background: linear-gradient(135deg, rgba(255,255,255,0.3), rgba(255,255,255,0.1));
                border: 3px solid rgba(255,255,255,0.5);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 25px;
                position: relative;
                transition: all 0.3s ease;
            }

            .modal-mic-btn:hover {
                transform: scale(1.05);
                background: linear-gradient(135deg, rgba(255,255,255,0.4), rgba(255,255,255,0.2));
            }

            .modal-mic-btn:active {
                transform: scale(0.95);
            }

            .modal-mic-btn .mic-icon {
                font-size: 48px;
                z-index: 2;
            }

            .modal-mic-btn .mic-ring {
                position: absolute;
                width: 100%;
                height: 100%;
                border-radius: 50%;
                border: 2px solid rgba(255,255,255,0.3);
                opacity: 0;
                animation: none;
            }

            .modal-mic-btn.listening {
                background: linear-gradient(135deg, #43e97b, #38f9d7);
                border-color: #43e97b;
                box-shadow: 0 0 40px rgba(67, 233, 123, 0.6);
            }

            .modal-mic-btn.listening .mic-ring {
                animation: micRingPulse 1.5s ease-out infinite;
            }

            .modal-mic-btn.listening .mic-ring-2 {
                animation-delay: 0.75s;
            }

            .modal-mic-btn.thinking {
                background: linear-gradient(135deg, #667eea, #764ba2);
                border-color: #667eea;
                box-shadow: 0 0 40px rgba(102, 126, 234, 0.6);
                animation: thinkingPulse 1s ease-in-out infinite;
            }

            .modal-mic-btn.speaking {
                background: linear-gradient(135deg, #f093fb, #f5576c);
                border-color: #f093fb;
                box-shadow: 0 0 40px rgba(240, 147, 251, 0.6);
            }

            .modal-mic-btn.speaking .mic-ring {
                animation: speakingRing 0.8s ease-in-out infinite;
            }

            @keyframes micRingPulse {
                0% {
                    transform: scale(1);
                    opacity: 0.8;
                }
                100% {
                    transform: scale(1.8);
                    opacity: 0;
                }
            }

            @keyframes thinkingPulse {
                0%, 100% {
                    transform: scale(1);
                    opacity: 1;
                }
                50% {
                    transform: scale(1.05);
                    opacity: 0.8;
                }
            }

            @keyframes speakingRing {
                0%, 100% {
                    transform: scale(1);
                    opacity: 0.3;
                }
                50% {
                    transform: scale(1.2);
                    opacity: 0.6;
                }
            }
            
            .status-icon {
                font-size: 48px;
                margin-bottom: 10px;
                animation: statusIconFloat 3s ease-in-out infinite;
            }
            
            @keyframes statusIconFloat {
                0%, 100% {
                    transform: translateY(0) rotate(0deg);
                }
                50% {
                    transform: translateY(-10px) rotate(5deg);
                }
            }
            
            .voice-status.listening .status-icon {
                animation: voicePulse 1.5s ease-in-out infinite;
            }
            
            @keyframes voicePulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.2);
                }
            }
            
            .status-text {
                font-size: 24px;
                font-weight: 700;
                color: white;
                margin-bottom: 10px;
            }
            
            .status-subtext {
                font-size: 16px;
                color: rgba(255, 255, 255, 0.8);
                font-weight: 500;
            }
            
            .voice-transcript {
                background: rgba(255, 255, 255, 0.15);
                border-radius: 16px;
                padding: 20px;
                margin: 20px 0;
                min-height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 18px;
                font-weight: 600;
                text-align: center;
            }
            
            .voice-suggestions {
                text-align: center;
            }
            
            .suggestion-title {
                color: rgba(255, 255, 255, 0.9);
                font-size: 14px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-bottom: 15px;
            }
            
            .suggestion-items {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .suggestion-btn {
                background: rgba(255, 255, 255, 0.15);
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-radius: 12px;
                padding: 14px 20px;
                color: white;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                text-align: left;
                transition: all 0.3s;
            }
            
            .suggestion-btn:hover {
                background: rgba(255, 255, 255, 0.25);
                transform: translateX(5px);
            }
            
            @media (max-width: 768px) {
                .footer-container {
                    padding: 0 15px 15px;
                }
                
                .mic-btn, .tracking-btn, .plus-btn {
                    width: 43px;
                    height: 43px;
                    font-size: 21px;
                }
                
                .plus-btn {
                    font-size: 24px;
                }
                
                .voice-modal-content {
                    padding: 50px 30px;
                }
                
                .status-icon {
                    font-size: 64px;
                }
                
                .status-text {
                    font-size: 20px;
                }
            }
        </style>
        
        <div class="menu-backdrop" id="menuBackdrop" onclick="closePlusMenu()"></div>
        
        <div class="footer-container">
            <button class="footer-btn mic-btn" id="micBtn" onclick="SuziVoice.open()" aria-label="Voice Assistant">
                🎤
            </button>

            <div class="footer-right">
                <button class="footer-btn help-btn-footer" id="helpBtn" title="Help">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </button>
    
                <a href="/tracking/index.php" class="footer-btn tracking-btn">
                 📍
                </a>
                
                <div class="plus-wrapper">
                    <div class="plus-menu" id="plusMenu">
                        <a href="/notifications/" class="plus-menu-item">
                            <span class="plus-menu-icon">🔔</span>
                            <span>Notifications</span>
                        </a>
                        <a href="/shopping/" class="plus-menu-item">
                            <span class="plus-menu-icon">🛒</span>
                            <span>Shopping</span>
                        </a>
                        <a href="/notes/" class="plus-menu-item">
                            <span class="plus-menu-icon">📝</span>
                            <span>Note</span>
                        </a>
                        <a href="/schedule/" class="plus-menu-item">
                            <span class="plus-menu-icon">⏰</span>
                            <span>Reminder</span>
                        </a>
                        <a href="/calendar/" class="plus-menu-item">
                            <span class="plus-menu-icon">📅</span>
                            <span>Event</span>
                        </a>
                        <a href="/messages/" class="plus-menu-item">
                            <span class="plus-menu-icon">💬</span>
                            <span>Message</span>
                        </a>
                    </div>
                    
                    <button class="footer-btn plus-btn" id="plusBtn" onclick="togglePlusMenu()">
                        ➕
                    </button>
                </div>
            </div>
        </div>
    </footer>

    <div class="voice-modal" id="voiceModal">
        <div class="voice-modal-content">
            <button class="close-voice-modal" onclick="SuziVoice.close()" aria-label="Close">✕</button>

            <div class="voice-status" id="voiceStatus">
                <div class="status-icon" id="statusIcon">🎤</div>
                <div class="status-text" id="statusText">Tap mic to speak</div>
                <div class="status-subtext" id="statusSubtext">I'm ready to help</div>
            </div>

            <!-- Large tappable mic button -->
            <button class="modal-mic-btn" id="modalMicBtn" onclick="SuziVoice.toggleListening()" aria-label="Start listening">
                <span class="mic-icon">🎤</span>
                <span class="mic-ring"></span>
                <span class="mic-ring mic-ring-2"></span>
            </button>

            <div class="voice-transcript" id="voiceTranscript">
                Tap the microphone to ask me anything
            </div>

            <div class="voice-suggestions" id="voiceSuggestions">
                <div class="suggestion-title">Try asking me:</div>
                <div class="suggestion-items">
                    <button onclick="SuziVoice.getInstance().executeSuggestion('Tell me a bedtime story')" class="suggestion-btn">
                        📖 "Tell me a bedtime story"
                    </button>
                    <button onclick="SuziVoice.getInstance().executeSuggestion('Who was Nelson Mandela')" class="suggestion-btn">
                        🧠 "Who was Nelson Mandela?"
                    </button>
                    <button onclick="SuziVoice.getInstance().executeSuggestion('Add bread and milk to shopping')" class="suggestion-btn">
                        🛒 "Add bread and milk to shopping"
                    </button>
                    <button onclick="SuziVoice.getInstance().executeSuggestion('Tell me a joke')" class="suggestion-btn">
                        😄 "Tell me a joke"
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="/shared/js/voice-assistant-advanced.js?v=<?php echo $cacheVersion; ?>"></script>
    <!-- app.js already loaded in header.php - not duplicated here -->
    
    <?php if (isset($pageJS)): ?>
        <?php foreach ((array)$pageJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        let plusMenuOpen = false;
        
        function togglePlusMenu() {
            const menu = document.getElementById('plusMenu');
            const btn = document.getElementById('plusBtn');
            const backdrop = document.getElementById('menuBackdrop');
            
            plusMenuOpen = !plusMenuOpen;
            
            if (plusMenuOpen) {
                menu.classList.add('active');
                btn.classList.add('active');
                backdrop.classList.add('active');
            } else {
                menu.classList.remove('active');
                btn.classList.remove('active');
                backdrop.classList.remove('active');
            }
        }
        
        function closePlusMenu() {
            if (plusMenuOpen) togglePlusMenu();
        }
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closePlusMenu();
                const voiceModal = document.getElementById('voiceModal');
                if (voiceModal && voiceModal.classList.contains('active')) {
                    SuziVoice.close();
                }
            }
        });
        
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('loaded');
            // Loader hide is handled by header.js hideLoader() - no duplicate needed
        });
    </script>
</body>
</html>