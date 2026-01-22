<?php
/**
 * Holiday Traveling - Edit Trip View
 */
?>

<div class="ht-edit-trip">
    <!-- Page Header -->
    <div class="ht-page-header">
        <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-back-link">← Back to Trip</a>
        <h1 class="ht-page-title">
            <span class="ht-title-icon">✏️</span>
            Edit Trip
        </h1>
    </div>

    <!-- Edit Trip Form -->
    <form id="editTripForm" class="ht-form" data-trip-id="<?php echo $trip['id']; ?>">
        <?php echo HT_CSRF::field(); ?>
        <input type="hidden" name="id" value="<?php echo $trip['id']; ?>">

        <!-- Basic Info -->
        <div class="ht-form-section">
            <h3 class="ht-form-section-title">Basic Information</h3>

            <div class="ht-form-group">
                <label for="title" class="ht-label">Trip Title *</label>
                <input type="text" id="title" name="title" class="ht-input"
                       value="<?php echo htmlspecialchars($trip['title']); ?>" required>
            </div>

            <div class="ht-form-row">
                <div class="ht-form-group">
                    <label for="destination" class="ht-label">Destination *</label>
                    <input type="text" id="destination" name="destination" class="ht-input"
                           value="<?php echo htmlspecialchars($trip['destination']); ?>" required>
                </div>
                <div class="ht-form-group">
                    <label for="origin" class="ht-label">Origin</label>
                    <input type="text" id="origin" name="origin" class="ht-input"
                           value="<?php echo htmlspecialchars($trip['origin'] ?? ''); ?>">
                </div>
            </div>

            <div class="ht-form-row">
                <div class="ht-form-group">
                    <label for="startDate" class="ht-label">Start Date *</label>
                    <input type="date" id="startDate" name="start_date" class="ht-input"
                           value="<?php echo $trip['start_date']; ?>" required>
                </div>
                <div class="ht-form-group">
                    <label for="endDate" class="ht-label">End Date *</label>
                    <input type="date" id="endDate" name="end_date" class="ht-input"
                           value="<?php echo $trip['end_date']; ?>" required>
                </div>
            </div>

            <div class="ht-form-group">
                <label for="status" class="ht-label">Status</label>
                <select id="status" name="status" class="ht-select">
                    <option value="draft" <?php echo $trip['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="planned" <?php echo $trip['status'] === 'planned' ? 'selected' : ''; ?>>Planned</option>
                    <option value="locked" <?php echo $trip['status'] === 'locked' ? 'selected' : ''; ?>>Locked</option>
                    <option value="active" <?php echo $trip['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="completed" <?php echo $trip['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $trip['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
        </div>

        <!-- Travelers -->
        <div class="ht-form-section">
            <h3 class="ht-form-section-title">Travelers</h3>

            <div class="ht-form-group">
                <label for="travelersCount" class="ht-label">Number of Travelers</label>
                <div class="ht-counter">
                    <button type="button" class="ht-counter-btn" data-action="decrement">-</button>
                    <input type="number" id="travelersCount" name="travelers_count" class="ht-counter-input"
                           value="<?php echo $trip['travelers_count']; ?>" min="1" max="50">
                    <button type="button" class="ht-counter-btn" data-action="increment">+</button>
                </div>
            </div>
        </div>

        <!-- Budget -->
        <div class="ht-form-section">
            <h3 class="ht-form-section-title">Budget</h3>

            <div class="ht-form-group">
                <label for="budgetCurrency" class="ht-label">Currency</label>
                <select id="budgetCurrency" name="budget_currency" class="ht-select">
                    <?php
                    $currencies = ['ZAR' => 'ZAR (R)', 'USD' => 'USD ($)', 'EUR' => 'EUR (€)', 'GBP' => 'GBP (£)'];
                    foreach ($currencies as $code => $label):
                    ?>
                    <option value="<?php echo $code; ?>" <?php echo $trip['budget_currency'] === $code ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ht-form-row ht-form-row-3">
                <div class="ht-form-group">
                    <label for="budgetMin" class="ht-label">Minimum</label>
                    <input type="number" id="budgetMin" name="budget_min" class="ht-input"
                           value="<?php echo $trip['budget_min'] ?? ''; ?>" min="0" step="100">
                </div>
                <div class="ht-form-group">
                    <label for="budgetComfort" class="ht-label">Comfortable</label>
                    <input type="number" id="budgetComfort" name="budget_comfort" class="ht-input"
                           value="<?php echo $trip['budget_comfort'] ?? ''; ?>" min="0" step="100">
                </div>
                <div class="ht-form-group">
                    <label for="budgetMax" class="ht-label">Maximum</label>
                    <input type="number" id="budgetMax" name="budget_max" class="ht-input"
                           value="<?php echo $trip['budget_max'] ?? ''; ?>" min="0" step="100">
                </div>
            </div>
        </div>

        <!-- Preferences -->
        <div class="ht-form-section">
            <h3 class="ht-form-section-title">Preferences</h3>

            <div class="ht-form-group">
                <label class="ht-label">Travel Style</label>
                <div class="ht-chip-group" data-name="travel_style">
                    <?php
                    $styles = ['relaxed' => '😌 Relaxed', 'balanced' => '⚖️ Balanced', 'adventure' => '🏃 Adventure', 'luxury' => '✨ Luxury'];
                    $currentStyle = $preferences['travel_style'] ?? 'balanced';
                    foreach ($styles as $value => $label):
                    ?>
                    <button type="button" class="ht-chip <?php echo $currentStyle === $value ? 'ht-chip-selected' : ''; ?>" data-value="<?php echo $value; ?>">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="travel_style" value="<?php echo $currentStyle; ?>">
            </div>

            <div class="ht-form-group">
                <label class="ht-label">Pace</label>
                <div class="ht-chip-group" data-name="pace">
                    <?php
                    $paces = ['relaxed' => '🐢 Relaxed', 'balanced' => '🚶 Balanced', 'packed' => '🏃 Packed'];
                    $currentPace = $preferences['pace'] ?? 'balanced';
                    foreach ($paces as $value => $label):
                    ?>
                    <button type="button" class="ht-chip <?php echo $currentPace === $value ? 'ht-chip-selected' : ''; ?>" data-value="<?php echo $value; ?>">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="pace" value="<?php echo $currentPace; ?>">
            </div>

            <div class="ht-form-group">
                <label class="ht-label">Interests (select multiple)</label>
                <div class="ht-chip-group ht-chip-group-multi" data-name="interests">
                    <?php
                    $allInterests = ['beaches' => '🏖️ Beaches', 'hiking' => '🥾 Hiking', 'culture' => '🏛️ Culture', 'food' => '🍽️ Food', 'wildlife' => '🦁 Wildlife', 'nightlife' => '🌙 Nightlife', 'shopping' => '🛍️ Shopping', 'photography' => '📸 Photography'];
                    $currentInterests = $preferences['interests'] ?? [];
                    if (is_string($currentInterests)) $currentInterests = explode(',', $currentInterests);
                    foreach ($allInterests as $value => $label):
                    ?>
                    <button type="button" class="ht-chip <?php echo in_array($value, $currentInterests) ? 'ht-chip-selected' : ''; ?>" data-value="<?php echo $value; ?>">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="interests" value="<?php echo implode(',', $currentInterests); ?>">
            </div>

            <div class="ht-form-group">
                <label for="dietaryPrefs" class="ht-label">Dietary Preferences</label>
                <input type="text" id="dietaryPrefs" name="dietary_prefs" class="ht-input"
                       value="<?php echo htmlspecialchars($preferences['dietary'] ?? ''); ?>"
                       placeholder="e.g., vegetarian, halal, no seafood">
            </div>

            <div class="ht-form-group">
                <label for="mobilityNotes" class="ht-label">Mobility/Accessibility Notes</label>
                <input type="text" id="mobilityNotes" name="mobility_notes" class="ht-input"
                       value="<?php echo htmlspecialchars($preferences['mobility'] ?? ''); ?>"
                       placeholder="e.g., wheelchair accessible, limited walking">
            </div>

            <div class="ht-form-group">
                <label for="additionalNotes" class="ht-label">Additional Notes</label>
                <textarea id="additionalNotes" name="additional_notes" class="ht-textarea" rows="3"
                          placeholder="Any other preferences or requirements..."><?php echo htmlspecialchars($preferences['notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="ht-form-actions">
            <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline">
                Cancel
            </a>
            <button type="submit" id="saveChangesBtn" class="ht-btn ht-btn-primary">
                Save Changes
            </button>
        </div>
    </form>

    <!-- Danger Zone -->
    <div class="ht-danger-zone">
        <h3 class="ht-danger-title">Danger Zone</h3>
        <div class="ht-danger-actions">
            <button id="deleteTripBtn" class="ht-btn ht-btn-danger">
                Delete Trip
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Delete Trip?</h3>
        <p class="ht-modal-text">Are you sure you want to delete "<strong><?php echo htmlspecialchars($trip['title']); ?></strong>"? This action cannot be undone.</p>
        <div class="ht-modal-actions">
            <button class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
            <button class="ht-btn ht-btn-danger" data-action="confirm">Delete Trip</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const form = document.getElementById('editTripForm');
    const saveBtn = document.getElementById('saveChangesBtn');
    const deleteBtn = document.getElementById('deleteTripBtn');
    const deleteModal = document.getElementById('deleteModal');
    const tripId = <?php echo $trip['id']; ?>;

    // Initialize counters
    document.querySelectorAll('.ht-counter').forEach(counter => {
        const input = counter.querySelector('.ht-counter-input');
        counter.querySelector('[data-action="decrement"]')?.addEventListener('click', () => {
            const min = parseInt(input.min) || 1;
            if (parseInt(input.value) > min) input.value = parseInt(input.value) - 1;
        });
        counter.querySelector('[data-action="increment"]')?.addEventListener('click', () => {
            const max = parseInt(input.max) || 50;
            if (parseInt(input.value) < max) input.value = parseInt(input.value) + 1;
        });
    });

    // Initialize chip groups
    document.querySelectorAll('.ht-chip-group').forEach(group => {
        const isMulti = group.classList.contains('ht-chip-group-multi');
        const hiddenInput = group.nextElementSibling;
        const chips = group.querySelectorAll('.ht-chip');

        chips.forEach(chip => {
            chip.addEventListener('click', function() {
                if (isMulti) {
                    this.classList.toggle('ht-chip-selected');
                    const selected = Array.from(group.querySelectorAll('.ht-chip-selected')).map(c => c.dataset.value);
                    if (hiddenInput) hiddenInput.value = selected.join(',');
                } else {
                    chips.forEach(c => c.classList.remove('ht-chip-selected'));
                    this.classList.add('ht-chip-selected');
                    if (hiddenInput) hiddenInput.value = this.dataset.value;
                }
            });
        });
    });

    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());

            const response = await HT.API.post('trips_update.php', data);

            HT.Toast.success('Trip updated successfully');
            window.location.href = `/holiday_traveling/trip_view.php?id=${tripId}`;
        } catch (error) {
            HT.Toast.error(error.message || 'Failed to update trip');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
        }
    });

    // Delete trip
    deleteBtn?.addEventListener('click', () => {
        deleteModal.style.display = 'flex';
    });

    deleteModal?.querySelector('.ht-modal-backdrop')?.addEventListener('click', () => {
        deleteModal.style.display = 'none';
    });

    deleteModal?.querySelector('[data-action="cancel"]')?.addEventListener('click', () => {
        deleteModal.style.display = 'none';
    });

    deleteModal?.querySelector('[data-action="confirm"]')?.addEventListener('click', async () => {
        try {
            await HT.API.delete(`trips_delete.php?id=${tripId}`);
            HT.Toast.success('Trip deleted');
            window.location.href = '/holiday_traveling/';
        } catch (error) {
            HT.Toast.error(error.message || 'Failed to delete trip');
        }
    });
})();
</script>

<style>
.ht-danger-zone {
    margin-top: 32px;
    padding: 20px;
    background: rgba(255, 65, 108, 0.1);
    border: 1px solid rgba(255, 65, 108, 0.3);
    border-radius: var(--ht-radius-md);
}

.ht-danger-title {
    font-size: 16px;
    font-weight: 700;
    color: #ff416c;
    margin: 0 0 12px 0;
}
</style>
