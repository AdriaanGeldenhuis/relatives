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
<?php require __DIR__ . '/password-fields.php'; ?>
