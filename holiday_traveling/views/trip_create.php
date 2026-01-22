<?php
/**
 * Holiday Traveling - Create Trip View
 * Natural language prompt + advanced options
 */
?>

<div class="ht-create-trip">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/" class="ht-back-link">← Back to Trips</a>
        <h1 class="ht-page-title">
            <span class="ht-title-icon">✨</span>
            Plan a New Trip
        </h1>
        <p class="ht-page-subtitle">Describe your trip and let AI create the perfect plan</p>
    </div>

    <!-- Quick Prompt Section -->
    <div class="ht-section ht-section-highlight">
        <div class="ht-prompt-box">
            <label for="quickPrompt" class="ht-prompt-label">
                <span class="ht-prompt-icon">💬</span>
                Tell us about your trip
            </label>
            <textarea
                id="quickPrompt"
                class="ht-prompt-input"
                placeholder="e.g., Cape Town, 10-18 April, family of 4 with kids aged 8 and 12, mid budget, love beaches and hiking, need some relaxation time..."
                rows="4"
            ></textarea>
            <div class="ht-prompt-examples">
                <span class="ht-examples-label">Examples:</span>
                <button type="button" class="ht-example-chip" data-prompt="Weekend getaway to Drakensberg, couple, budget-friendly, love hiking">Drakensberg weekend</button>
                <button type="button" class="ht-example-chip" data-prompt="Cape Town, 7 days, family of 4 with teenagers, mid-range budget, want beaches, Table Mountain, and some adventure">Cape Town family</button>
                <button type="button" class="ht-example-chip" data-prompt="Kruger safari, 5 days, 2 adults, premium experience, first time safari visitors">Kruger safari</button>
            </div>
        </div>
    </div>

    <!-- Create Trip Form -->
    <form id="createTripForm" class="ht-form">
        <?php echo HT_CSRF::field(); ?>

        <!-- Advanced Options Accordion -->
        <div class="ht-accordion">
            <button type="button" class="ht-accordion-trigger" aria-expanded="false">
                <span class="ht-accordion-icon">⚙️</span>
                <span class="ht-accordion-title">Advanced Options</span>
                <span class="ht-accordion-arrow">▼</span>
            </button>
            <div class="ht-accordion-content" hidden>
                <!-- Basic Info -->
                <div class="ht-form-section">
                    <h3 class="ht-form-section-title">Basic Information</h3>

                    <div class="ht-form-group">
                        <label for="title" class="ht-label">Trip Title</label>
                        <input type="text" id="title" name="title" class="ht-input" placeholder="e.g., Summer Family Vacation">
                    </div>

                    <div class="ht-form-row">
                        <div class="ht-form-group">
                            <label for="destination" class="ht-label">Destination *</label>
                            <input type="text" id="destination" name="destination" class="ht-input" placeholder="e.g., Cape Town" required>
                        </div>
                        <div class="ht-form-group">
                            <label for="origin" class="ht-label">Origin</label>
                            <input type="text" id="origin" name="origin" class="ht-input" placeholder="e.g., Johannesburg">
                        </div>
                    </div>

                    <div class="ht-form-row">
                        <div class="ht-form-group">
                            <label for="startDate" class="ht-label">Start Date *</label>
                            <input type="date" id="startDate" name="start_date" class="ht-input" required>
                        </div>
                        <div class="ht-form-group">
                            <label for="endDate" class="ht-label">End Date *</label>
                            <input type="date" id="endDate" name="end_date" class="ht-input" required>
                        </div>
                    </div>
                </div>

                <!-- Travelers -->
                <div class="ht-form-section">
                    <h3 class="ht-form-section-title">Travelers</h3>

                    <div class="ht-form-group">
                        <label for="travelersCount" class="ht-label">Number of Travelers</label>
                        <div class="ht-counter">
                            <button type="button" class="ht-counter-btn" data-action="decrement">-</button>
                            <input type="number" id="travelersCount" name="travelers_count" class="ht-counter-input" value="2" min="1" max="50">
                            <button type="button" class="ht-counter-btn" data-action="increment">+</button>
                        </div>
                    </div>

                    <div id="travelersDetails" class="ht-travelers-details">
                        <!-- Dynamically populated -->
                    </div>
                </div>

                <!-- Budget -->
                <div class="ht-form-section">
                    <h3 class="ht-form-section-title">Budget</h3>

                    <div class="ht-form-group">
                        <label for="budgetCurrency" class="ht-label">Currency</label>
                        <select id="budgetCurrency" name="budget_currency" class="ht-select">
                            <option value="ZAR" selected>ZAR (R)</option>
                            <option value="USD">USD ($)</option>
                            <option value="EUR">EUR (€)</option>
                            <option value="GBP">GBP (£)</option>
                        </select>
                    </div>

                    <div class="ht-form-row ht-form-row-3">
                        <div class="ht-form-group">
                            <label for="budgetMin" class="ht-label">Minimum</label>
                            <input type="number" id="budgetMin" name="budget_min" class="ht-input" placeholder="5000" min="0" step="100">
                        </div>
                        <div class="ht-form-group">
                            <label for="budgetComfort" class="ht-label">Comfortable</label>
                            <input type="number" id="budgetComfort" name="budget_comfort" class="ht-input" placeholder="10000" min="0" step="100">
                        </div>
                        <div class="ht-form-group">
                            <label for="budgetMax" class="ht-label">Maximum</label>
                            <input type="number" id="budgetMax" name="budget_max" class="ht-input" placeholder="15000" min="0" step="100">
                        </div>
                    </div>
                </div>

                <!-- Preferences -->
                <div class="ht-form-section">
                    <h3 class="ht-form-section-title">Preferences</h3>

                    <div class="ht-form-group">
                        <label class="ht-label">Travel Style</label>
                        <div class="ht-chip-group" data-name="travel_style">
                            <button type="button" class="ht-chip" data-value="relaxed">😌 Relaxed</button>
                            <button type="button" class="ht-chip ht-chip-selected" data-value="balanced">⚖️ Balanced</button>
                            <button type="button" class="ht-chip" data-value="adventure">🏃 Adventure</button>
                            <button type="button" class="ht-chip" data-value="luxury">✨ Luxury</button>
                        </div>
                        <input type="hidden" name="travel_style" value="balanced">
                    </div>

                    <div class="ht-form-group">
                        <label class="ht-label">Pace</label>
                        <div class="ht-chip-group" data-name="pace">
                            <button type="button" class="ht-chip" data-value="relaxed">🐢 Relaxed</button>
                            <button type="button" class="ht-chip ht-chip-selected" data-value="balanced">🚶 Balanced</button>
                            <button type="button" class="ht-chip" data-value="packed">🏃 Packed</button>
                        </div>
                        <input type="hidden" name="pace" value="balanced">
                    </div>

                    <div class="ht-form-group">
                        <label class="ht-label">Interests (select multiple)</label>
                        <div class="ht-chip-group ht-chip-group-multi" data-name="interests">
                            <button type="button" class="ht-chip" data-value="beaches">🏖️ Beaches</button>
                            <button type="button" class="ht-chip" data-value="hiking">🥾 Hiking</button>
                            <button type="button" class="ht-chip" data-value="culture">🏛️ Culture</button>
                            <button type="button" class="ht-chip" data-value="food">🍽️ Food</button>
                            <button type="button" class="ht-chip" data-value="wildlife">🦁 Wildlife</button>
                            <button type="button" class="ht-chip" data-value="nightlife">🌙 Nightlife</button>
                            <button type="button" class="ht-chip" data-value="shopping">🛍️ Shopping</button>
                            <button type="button" class="ht-chip" data-value="photography">📸 Photography</button>
                        </div>
                        <input type="hidden" name="interests" value="">
                    </div>

                    <div class="ht-form-group">
                        <label for="dietaryPrefs" class="ht-label">Dietary Preferences</label>
                        <input type="text" id="dietaryPrefs" name="dietary_prefs" class="ht-input" placeholder="e.g., vegetarian, halal, no seafood">
                    </div>

                    <div class="ht-form-group">
                        <label for="mobilityNotes" class="ht-label">Mobility/Accessibility Notes</label>
                        <input type="text" id="mobilityNotes" name="mobility_notes" class="ht-input" placeholder="e.g., wheelchair accessible, limited walking">
                    </div>

                    <div class="ht-form-group">
                        <label for="additionalNotes" class="ht-label">Additional Notes</label>
                        <textarea id="additionalNotes" name="additional_notes" class="ht-textarea" rows="3" placeholder="Any other preferences or requirements..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="ht-form-actions">
            <button type="button" id="saveDraftBtn" class="ht-btn ht-btn-outline">
                Save as Draft
            </button>
            <button type="submit" id="generatePlanBtn" class="ht-btn ht-btn-primary ht-btn-lg">
                <span class="ht-btn-icon">🤖</span>
                <span class="ht-btn-text">Generate AI Plan</span>
            </button>
        </div>
    </form>

    <!-- AI Loading State -->
    <div id="aiLoadingState" class="ht-ai-loading" style="display: none;">
        <div class="ht-ai-loading-content">
            <div class="ht-ai-spinner"></div>
            <h3 class="ht-ai-loading-title">Creating your perfect trip...</h3>
            <p class="ht-ai-loading-text">AI is analyzing destinations, activities, and creating a personalized itinerary</p>
            <div class="ht-ai-loading-steps">
                <div class="ht-loading-step" data-step="1">Analyzing destination...</div>
                <div class="ht-loading-step" data-step="2">Finding accommodations...</div>
                <div class="ht-loading-step" data-step="3">Planning activities...</div>
                <div class="ht-loading-step" data-step="4">Optimizing itinerary...</div>
            </div>
        </div>
    </div>
</div>
