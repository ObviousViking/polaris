<?php
// includes/produced_item_receipts.php
//
// Shared logic for rendering produced-item book-out/book-in receipts, and
// for saving a snapshot of one to disk (plus a DB record) at the moment
// it's generated - same approach as includes/exhibit_receipts.php, against
// case_items instead of exhibits.

require_once __DIR__ . '/settings.php';

// Fetches the case_items rows needed to render a receipt of $receiptType
// for the given item ids.
function fetch_produced_item_receipt_rows(mysqli $conn, array $itemIds, string $receiptType): array
{
    $ids = implode(',', array_map('intval', $itemIds));
    if ($ids === '') {
        return [];
    }

    $query = "
        SELECT
            ci.item_id,
            ci.item_ref,
            ci.description,
            ci.file_count,
            ci.booked_out_at,
            ci.returned_at,
            t.type_name,
            j.custom_ref,
            j.created_by
        FROM case_items ci
        JOIN case_item_types t ON ci.type_id = t.type_id
        JOIN jobs j ON ci.job_id = j.job_id
        WHERE ci.item_id IN ($ids)
    ";

    $result = $conn->query($query);
    if (!$result) {
        return [];
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'item_id'      => $row['item_id'],
            'item_ref'     => $row['item_ref'] ?? '',
            'type_name'    => $row['type_name'] ?? '',
            'description'  => $row['description'] ?? '',
            'file_count'   => $row['file_count'],
            'booked_out_at' => $row['booked_out_at'] ?? '',
            'returned_at'  => $row['returned_at'] ?? '',
            'custom_ref'   => $row['custom_ref'] ?? '',
            'created_by'   => $row['created_by'] ?? '',
        ];
    }
    return $items;
}

// Renders the full standalone receipt HTML page - same layout/style as
// exhibit receipts, just labelled for produced items. $extraLabel is who it
// was booked out to, or who returned it.
function render_produced_item_receipt_html(array $items, string $receiptType, string $jobCustomRef, string $userName, string $extraLabel = ''): string
{
    $titleSuffix = $receiptType === 'out' ? '(Book Out)' : '(Book Back In)';
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Produced Item Receipt <?php echo $titleSuffix; ?></title>
    <style>
    body {
        font-family: 'Arial', sans-serif;
        background: #fff;
        color: #000;
        padding: 20px;
        margin: 0;
    }

    .receipt {
        max-width: 800px;
        margin: 0 auto;
        border: 2px solid #333;
        padding: 20px;
        background: #fff;
    }

    .header {
        text-align: center;
        border-bottom: 2px solid #333;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }

    h1 {
        margin: 0;
        font-size: 24px;
        color: #000;
    }

    .job-info {
        font-size: 14px;
        margin: 10px 0;
    }

    .details {
        margin-bottom: 20px;
        font-size: 14px;
    }

    .details div {
        margin-bottom: 5px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 14px;
    }

    th,
    td {
        border: 1px solid #666;
        padding: 10px;
        text-align: center;
    }

    th {
        background: #f5f5f5;
        color: #000;
        font-weight: bold;
    }

    .signature-section {
        margin-top: 60px;
        font-size: 14px;
    }

    .signature-line {
        margin-top: 20px;
        border-bottom: 1px solid #333;
        width: 100%;
        height: 20px;
    }

    .no-print {
        text-align: center;
        margin-top: 20px;
    }

    button {
        padding: 8px 20px;
        background: #333;
        color: #fff;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    button:hover {
        background: #555;
    }

    @media print {
        .no-print {
            display: none;
        }

        body {
            padding: 0;
        }

        .receipt {
            border: none;
            padding: 10px;
        }
    }
    </style>
</head>

<body>
    <div class="receipt">
        <div class="header">
            <h1>Produced Item Receipt <?php echo $titleSuffix; ?></h1>
            <div class="job-info">
                <strong>Job Number:</strong> <?php echo htmlspecialchars($jobCustomRef); ?>
            </div>
        </div>

        <div class="details">
            <?php if ($receiptType === 'out'): ?>
            <div><strong>Booked Out By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Booked Out To:</strong> <?php echo htmlspecialchars($extraLabel); ?></div>
            <?php else: ?>
            <div><strong>Received By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Returned By:</strong> <?php echo htmlspecialchars($extraLabel); ?></div>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Item Ref</th>
                <th>Type</th>
                <th>Description</th>
                <th>No. of Files</th>
                <?php if ($receiptType === 'out'): ?>
                <th>Booked Out At</th>
                <?php else: ?>
                <th>Booked Out At</th>
                <th>Returned At</th>
                <?php endif; ?>
            </tr>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><?php echo htmlspecialchars($it['item_ref']); ?></td>
                <td><?php echo htmlspecialchars($it['type_name']); ?></td>
                <td><?php echo htmlspecialchars($it['description']); ?></td>
                <td><?php echo htmlspecialchars($it['file_count'] !== null && $it['file_count'] !== '' ? (string) $it['file_count'] : ''); ?></td>
                <td><?php echo htmlspecialchars($it['booked_out_at']); ?></td>
                <?php if ($receiptType !== 'out'): ?>
                <td><?php echo htmlspecialchars($it['returned_at']); ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="signature-section">
            <p><strong>Signature (<?php echo $receiptType === 'out' ? 'Book Out' : 'Book In'; ?>):</strong></p>
            <div class="signature-line"></div>
        </div>

        <div class="no-print">
            <button onclick="window.print();">Print Receipt</button>
        </div>
    </div>
</body>

</html>
    <?php
    return ob_get_clean();
}

// Renders and saves a receipt to disk for the given produced items (fetched
// fresh from their current DB state), and records it in
// case_item_receipts/case_item_receipt_items so it can be found again
// later. Returns the new receipt_id, or null if nothing could be saved.
function save_produced_item_receipt(mysqli $conn, int $jobId, string $receiptType, array $itemIds, int $generatedBy, string $extraLabel = ''): ?int
{
    $items = fetch_produced_item_receipt_rows($conn, $itemIds, $receiptType);
    if (empty($items)) {
        return null;
    }

    $jobCustomRef = $items[0]['custom_ref'];
    return save_produced_item_receipt_with_rows($conn, $jobId, $receiptType, $items, $jobCustomRef, $generatedBy, $extraLabel);
}

// Same as save_produced_item_receipt(), but takes already-built item rows -
// for 'in' (book-back-in) receipts, where the caller has to capture each
// item's booked_out_at plus the new returned_at before/while updating it.
function save_produced_item_receipt_with_rows(mysqli $conn, int $jobId, string $receiptType, array $items, string $jobCustomRef, int $generatedBy, string $extraLabel = ''): ?int
{
    if (empty($items)) {
        return null;
    }

    $userName = (string) $generatedBy;
    $userStmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = ? LIMIT 1");
    $userStmt->bind_param("i", $generatedBy);
    $userStmt->execute();
    $userStmt->bind_result($fullName);
    if ($userStmt->fetch()) {
        $userName = $fullName;
    }
    $userStmt->close();

    $html = render_produced_item_receipt_html($items, $receiptType, $jobCustomRef, $userName, $extraLabel);

    $storage = get_storage_settings($conn);
    $dir = $storage['paths']['produced_item_receipt_dir_fs'];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'receipt_' . $receiptType . '_' . $jobId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.html';
    $filePath = $dir . $filename;
    if (file_put_contents($filePath, $html) === false) {
        return null;
    }

    // booked_out_to also carries the "returned by" name on 'in' receipts -
    // same shape (one free-text name tied to the event), not worth a
    // dedicated column for.
    $stmt = $conn->prepare("
        INSERT INTO case_item_receipts (job_id, receipt_type, booked_out_to, file_path, generated_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $extraLabelParam = $extraLabel !== '' ? $extraLabel : null;
    $stmt->bind_param("isssi", $jobId, $receiptType, $extraLabelParam, $filePath, $generatedBy);
    $stmt->execute();
    $receiptId = $conn->insert_id;
    $stmt->close();

    $itemStmt = $conn->prepare("INSERT INTO case_item_receipt_items (receipt_id, item_id) VALUES (?, ?)");
    foreach ($items as $it) {
        $itemId = (int) $it['item_id'];
        $itemStmt->bind_param("ii", $receiptId, $itemId);
        $itemStmt->execute();
    }
    $itemStmt->close();

    return $receiptId;
}

// Every saved receipt that covers any of the given item ids, oldest first -
// for "View Receipt" links on job.php.
function get_receipts_for_produced_items(mysqli $conn, array $itemIds): array
{
    $ids = implode(',', array_map('intval', array_filter($itemIds)));
    if ($ids === '') {
        return [];
    }

    $result = $conn->query("
        SELECT ciri.item_id, cir.receipt_id, cir.receipt_type, cir.generated_at
        FROM case_item_receipt_items ciri
        JOIN case_item_receipts cir ON ciri.receipt_id = cir.receipt_id
        WHERE ciri.item_id IN ($ids)
        ORDER BY cir.generated_at ASC, cir.receipt_id ASC
    ");

    $byItem = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $byItem[(int) $row['item_id']][] = [
                'receipt_id'   => (int) $row['receipt_id'],
                'receipt_type' => $row['receipt_type'],
                'generated_at' => $row['generated_at'],
            ];
        }
    }
    return $byItem;
}
