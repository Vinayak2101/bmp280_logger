<?php
/**
 * Root entry point: serves the dashboard at https://<domain>/ .
 * The device keeps posting to bmp280.php, which stays a separate URL.
 */
require __DIR__ . '/bmp280.php';