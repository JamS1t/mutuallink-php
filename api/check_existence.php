<?php
declare(strict_types=1);

/**
 * POST api/check_existence.php   body: {"field":"username|email","value":"...","exclude_id":0}
 * → 200 {"success":true,"data":{"exists":bool},"errors":[]}
 */

require_once __DIR__ . '/_bootstrap.php';
api_bootstrap('POST', 'users', 'view');

$body = read_json_body();
$field = $body['field'] ?? '';
$value = is_string($body['value'] ?? null) ? trim($body['value']) : '';
$excludeId = filter_var($body['exclude_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

// Whitelist: the column name can never come from the user.
$columns = ['username' => 'username', 'email' => 'email'];
if (!isset($columns[$field])) {
    json_out(422, null, ['Invalid field.']);
}
if ($value === '' || mb_strlen($value) > 150 || $excludeId === false) {
    json_out(422, null, ['Invalid value.']);
}

$stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE ' . $columns[$field] . ' = :v AND user_id <> :id');
$stmt->execute([':v' => $value, ':id' => $excludeId]);

json_out(200, ['exists' => (int) $stmt->fetchColumn() > 0]);
