<?php
// Check if user is SUPER ADMIN (not just family owner)
if (!isset($user) || $user['role'] !== 'admin' || $user['family_id'] != 1) {
    header('Location: /home/index.php');
    exit;
}