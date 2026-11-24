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
