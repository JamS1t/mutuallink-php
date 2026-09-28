<?php
declare(strict_types=1);

/**
 * GET api/member_lookup.php?q=dela
 * → 200 {"success":true,"data":[{"member_id":1,"name":"Dela Cruz, Juan","member_no":"FFMPC-2026-0001"}],"errors":[]}
 *
 * Prefix search ("term%") so the name and member_no indexes can be used;
 * a leading wildcard ("%term%") would force a full table scan.
 */

require_once __DIR__ . '/_bootstrap.php';
api_bootstrap('GET', 'members', 'view');

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 2 || mb_strlen($q) > 60) {
    json_out(422, null, ['Type at least 2 characters.']);
}

$like = addcslashes($q, '%_\\') . '%';
$stmt = db()->prepare(
    "SELECT member_id, member_no, status, CONCAT(last_name, ', ', first_name) AS name
       FROM members
      WHERE status IN ('active', 'applicant') AND (last_name LIKE :q1 OR first_name LIKE :q2 OR member_no LIKE :q3)
      ORDER BY last_name, first_name
      LIMIT 10"
);
$stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);

json_out(200, array_map(fn ($r) => [
    'member_id' => (int) $r['member_id'],
    'name'      => $r['name'] . ($r['status'] === 'applicant' ? ' (applicant)' : ''),
    'member_no' => $r['member_no'],
], $stmt->fetchAll()));
