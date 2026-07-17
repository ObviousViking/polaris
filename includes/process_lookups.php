<?php
// includes/process_lookups.php
//
// The fixed set of sources a Process Builder "lookup" field can pull its
// dropdown options from (see process_fields.lookup_source). Deliberately a
// small hardcoded list with a fixed query each, rather than letting an
// admin point a field at an arbitrary table/column - that would mean
// interpolating admin-supplied identifiers into SQL, which is a real
// injection risk even from a trusted admin (typos, copy-paste mistakes, or
// a compromised admin account). Add a new source here (a new array entry)
// when a new field needs one - it's a code change, not a schema one.

const PROCESS_FIELD_LOOKUP_SOURCES = [
    'assets' => [
        'label' => 'Assets (Logistics Hub)',
        'query' => "SELECT DISTINCT friendly_name AS value FROM assets WHERE availability != 'Destroyed' ORDER BY friendly_name",
    ],
    'users' => [
        'label' => 'Users',
        'query' => "SELECT CONCAT(first_name, ' ', last_name) AS value FROM users WHERE is_active = 1 ORDER BY first_name, last_name",
    ],
];

function get_process_field_lookup_options(mysqli $conn, ?string $source): array
{
    if ($source === null || !isset(PROCESS_FIELD_LOOKUP_SOURCES[$source])) {
        return [];
    }

    $options = [];
    $result = $conn->query(PROCESS_FIELD_LOOKUP_SOURCES[$source]['query']);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if ($row['value'] !== null && $row['value'] !== '') {
                $options[] = $row['value'];
            }
        }
    }
    return $options;
}
