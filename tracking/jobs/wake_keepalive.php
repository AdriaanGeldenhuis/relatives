<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Existing crontabs can still reference this entry. Android's consent-scoped
// service and restart worker maintain tracking without scheduled phone pushes.
echo "Scheduled tracking pushes are retired.\n";
