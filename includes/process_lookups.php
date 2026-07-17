<?php
// includes/process_lookups.php
//
// Fixed set of sources a Process Builder "lookup" field can pull options
// from. Hardcoded rather than admin-configurable, to avoid interpolating
// admin-supplied table/column names into SQL.

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
