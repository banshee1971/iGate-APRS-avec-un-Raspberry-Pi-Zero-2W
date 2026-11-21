<?php
require __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    aprs_csrf_check();
    aprs_logout();
}
header('Location: /admin/login.php');
