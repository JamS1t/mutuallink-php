<?php
declare(strict_types=1);

/**
 * Shared setup for the small JSON endpoints.
 * Same guards as pages: authentication → RBAC → CSRF (header), plus method check.
 */

require_once __DIR__ . '/../config/bootstrap.php';

function api_bootstrap(string $method, string $module, string $action = 'view'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_out(405, null, ['Method not allowed.']);
    }
    require_login(true);
    require_permission($module, $action, true);

    // Every endpoint (GET included) requires the token: the data is private.
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_out(403, null, ['Security token expired. Refresh the page and try again.']);
    }
}

/**
 * Reads a JSON request body from php://input ($_POST is empty for application/json).
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        json_out(400, null, ['Malformed JSON: ' . json_last_error_msg()]);
    }
    return $data;
}
