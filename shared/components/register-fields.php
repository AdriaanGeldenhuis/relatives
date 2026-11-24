<div class="registration-row">
    <div class="form-field">
        <label for="full_name">Your full name</label>
        <input id="full_name" name="full_name" type="text" autocomplete="name" value="<?= htmlspecialchars($formData['full_name']) ?>" required>
    </div>
    <div class="form-field">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" value="<?= htmlspecialchars($formData['email']) ?>" required>
    </div>
</div>
<div class="form-field">
    <label for="password">Password</label>
    <div class="password-field">
        <input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-hint" minlength="12" required>
        <button type="button" class="password-toggle" data-password="password" aria-controls="password" hidden>Show</button>
    </div>
    <small class="form-hint" id="password-hint">At least 12 characters.</small>
    <?php if (!empty($showPasswordStrength)): ?>
        <meter class="password-strength" id="password-strength" min="0" max="5" low="3" high="4" optimum="5" value="0" aria-label="Estimated password strength" hidden></meter>
    <?php endif; ?>
</div>
<div class="form-field">
    <label for="confirm_password">Confirm password</label>
    <div class="password-field">
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" aria-describedby="password-match" minlength="12" required>
        <button type="button" class="password-toggle" data-password="confirm_password" aria-controls="confirm_password" hidden>Show</button>
    </div>
    <small class="form-hint password-match" id="password-match" aria-live="polite"></small>
</div>
