<?php

function avatarPath($userId): string
{
    return __DIR__ . '/../../saves/' . (int)$userId . '/avatar/avatar.webp';
}

function avatarUrl($userId): string
{
    $path = avatarPath($userId);
    $version = is_file($path) ? filemtime($path) : 0;
    return '/saves/' . (int)$userId . '/avatar/avatar.webp?v=' . $version;
}

function renderProfileAvatar(array $user): void
{
    if (is_file(avatarPath($user['id']))) {
        echo '<img src="' . htmlspecialchars(avatarUrl($user['id'])) . '" alt="" width="104" height="104">';
        return;
    }
    $name = trim($user['name'] ?? '') ?: '?';
    echo '<span aria-hidden="true">' . htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}
