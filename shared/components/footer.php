<?php
/**
 * GLOBAL FOOTER - WITH ADVANCED VOICE ASSISTANT
 */
?>
    <!-- Global Footer -->
    <footer class="global-footer" id="globalFooter">
        
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

                <a href="/tracking/app/" class="footer-btn tracking-btn" aria-label="Tracking">
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
