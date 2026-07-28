<?php
// includes/process_lookups.php
//
// Fixed set of sources a Process Builder "lookup" field can pull options
// from. Hardcoded rather than admin-configurable, to avoid interpolating
// admin-supplied table/column names into SQL.

const PROCESS_FIELD_LOOKUP_SOURCES = [
    'assets' => [
        'label' => 'Assets (Logistics Hub)',
        // Deployed only - a lookup like "Software Used" or "Write Blocker"
        // shouldn't offer an asset that's in maintenance, out of service,
        // or destroyed. Narrowed further to one asset type at the field
        // level via lookup_asset_type_id - see get_process_field_lookup_options().
        'query' => "SELECT DISTINCT friendly_name AS value FROM assets WHERE availability = 'Deployed' ORDER BY friendly_name",
    ],
    'users' => [
        'label' => 'Users',
        'query' => "SELECT CONCAT(first_name, ' ', last_name) AS value FROM users WHERE is_active = 1 ORDER BY first_name, last_name",
    ],
];

// $assetTypeId, when given, narrows the 'assets' source to one asset type
// (e.g. only "Software" assets for a "Software Used" field). It's always
// bound as a parameter, never interpolated, so an admin picking a type from
// the dropdown can't affect the query shape.
function get_process_field_lookup_options(mysqli $conn, ?string $source, ?int $assetTypeId = null): array
{
    if ($source === null || !isset(PROCESS_FIELD_LOOKUP_SOURCES[$source])) {
        return [];
    }

    $options = [];

    if ($source === 'assets' && $assetTypeId !== null) {
        $stmt = $conn->prepare("SELECT DISTINCT friendly_name AS value FROM assets WHERE availability = 'Deployed' AND asset_type_id = ? ORDER BY friendly_name");
        $stmt->bind_param("i", $assetTypeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ($row['value'] !== null && $row['value'] !== '') {
                $options[] = $row['value'];
            }
        }
        $stmt->close();
        return $options;
    }

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
