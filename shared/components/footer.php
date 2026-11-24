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
                gap: 3px;
                padding: 7px;
                border: 1px solid #d9dfd0;
                border-radius: 16px;
                background: #fffdf6;
                box-shadow: 0 8px 24px #14291f33;
                pointer-events: all;
                z-index: 1000;
            }

            .plus-menu.active { display: flex; }

            .plus-menu-item {
                display: flex;
                align-items: center;
                gap: 8px;
                min-height: 42px;
                padding: 5px 10px;
                border: 1px solid transparent;
                border-radius: 9px;
                color: #192e24;
                text-decoration: none;
                font-weight: 700;
                white-space: nowrap;
            }

            .plus-menu-item:hover, .plus-menu-item:focus-visible {
                background: #e8efdc;
                border-color: #d8e1cc;
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
            
            @media (max-width: 768px) {
                .footer-container {
                    padding: 0 15px 15px;
                }
                
                .tracking-btn, .plus-btn {
                    width: 43px;
                    height: 43px;
                    font-size: 21px;
                }
                
                .plus-btn {
                    font-size: 24px;
                }
                
            }
        </style>
        
        <div class="menu-backdrop" id="menuBackdrop" onclick="closePlusMenu()"></div>
        
        <div class="footer-container">
            <button type="button" class="footer-btn footer-assist-btn mic-btn" id="micBtn"
                    onclick="SuziVoice.open()" aria-haspopup="dialog" aria-controls="voiceModal" aria-expanded="false">
                <span class="footer-assist-icon assist-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
                <span>Suzi</span>
            </button>

            <div class="footer-right">
                <button type="button" class="footer-btn footer-assist-btn help-btn-footer" id="helpBtn"
                        aria-haspopup="dialog" aria-controls="helpPanel" aria-expanded="false">
                    <span class="footer-assist-icon" aria-hidden="true">?</span>
                    <span>Help</span>
                </button>

                <a href="/tracking/app/" class="footer-btn tracking-btn">
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
                    
                    <button class="footer-btn plus-btn" id="plusBtn" aria-label="Quick links" aria-controls="plusMenu" aria-expanded="false" onclick="togglePlusMenu()">
                        ➕
                    </button>
                </div>
            </div>
        </div>
    </footer>

    <dialog class="footer-assist-dialog voice-modal" id="voiceModal" aria-labelledby="voiceHeading">
        <div class="assist-panel">
            <header class="assist-header">
                <span class="assist-header-icon assist-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
                <div>
                    <h2 id="voiceHeading">Suzi</h2>
                    <p>Your voice assistant</p>
                </div>
                <button type="button" class="assist-close" onclick="SuziVoice.close()" aria-label="Close Suzi">×</button>
            </header>

            <div class="assist-scroll voice-content">
                <div class="voice-console" id="voiceConsole" data-state="ready">
                    <div class="voice-orbit">
                        <button type="button" class="modal-mic-btn" id="modalMicBtn" onclick="SuziVoice.toggleListening()" aria-label="Start listening" autofocus>
                            <svg class="voice-mic-icon" aria-hidden="true" width="35" height="35" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="9" y="2" width="6" height="12" rx="3"/>
                                <path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3m-3 0h6"/>
                            </svg>
                            <span class="voice-stop-icon" aria-hidden="true"></span>
                            <span class="assist-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
                        </button>
                    </div>
                    <div class="voice-status" role="status">
                        <strong id="statusText">Tap to speak</strong>
                        <p id="statusSubtext">Ask a question or try a command below.</p>
                    </div>
                </div>

                <div class="voice-exchange" id="voiceHeard" hidden>
                    <h3>You</h3>
                    <p id="voiceTranscript"></p>
                </div>
                <div class="voice-exchange voice-answer" id="voiceAnswer" hidden>
                    <h3>Suzi</h3>
                    <p id="voiceReply"></p>
                    <button type="button" class="assist-secondary voice-replay" id="voiceReplay" onclick="SuziVoice.replay()" hidden>↻ Play reply again</button>
                </div>

                <div id="voiceSuggestions">
                    <h3 class="assist-prompt-heading">Try a voice command</h3>
                    <div class="assist-prompts">
                        <button type="button" onclick="SuziVoice.executeSuggestion('Tell me a bedtime story')" class="assist-prompt">
                            <span class="assist-tile-icon" aria-hidden="true">📖</span><span>Tell me a bedtime story</span>
                        </button>
                        <button type="button" onclick="SuziVoice.executeSuggestion('Who was Nelson Mandela')" class="assist-prompt">
                            <span class="assist-tile-icon" aria-hidden="true">💬</span><span>Who was Nelson Mandela?</span>
                        </button>
                        <button type="button" onclick="SuziVoice.executeSuggestion('Open the shopping list')" class="assist-prompt">
                            <span class="assist-tile-icon" aria-hidden="true">🛒</span><span>Open the shopping list</span>
                        </button>
                        <button type="button" onclick="SuziVoice.executeSuggestion('Tell me a joke')" class="assist-prompt">
                            <span class="assist-tile-icon" aria-hidden="true">😄</span><span>Tell me a joke</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </dialog>

    <script src="/shared/js/planner-events.js?v=<?= filemtime(__DIR__ . '/../js/planner-events.js') ?>"></script>
    <script src="/shared/js/voice-assistant-advanced.js?v=<?php echo filemtime(__DIR__ . '/../js/voice-assistant-advanced.js'); ?>"
            data-write-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></script>
    <!-- app.js already loaded in header.php - not duplicated here -->
    
    <?php if (isset($pageJS)): ?>
        <?php foreach ((array)$pageJS as $js): ?>
            <script src="<?php echo $js; ?>?v=<?php echo $pageJSVersion ?? $buildTime; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        let plusMenuOpen = false;
        
        function togglePlusMenu() {
            const menu = document.getElementById('plusMenu');
            const btn = document.getElementById('plusBtn');
            const backdrop = document.getElementById('menuBackdrop');
            
            plusMenuOpen = !plusMenuOpen;
            btn.setAttribute('aria-expanded', String(plusMenuOpen));
            
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
            }
        });
        
        document.addEventListener('DOMContentLoaded', () => {
            document.body.classList.add('loaded');
            // Loader hide is handled by header.js hideLoader() - no duplicate needed
        });
    </script>
</body>
</html>
