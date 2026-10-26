<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_newsletter.php';
require_platform_admin();
newsletter_export_csv();
