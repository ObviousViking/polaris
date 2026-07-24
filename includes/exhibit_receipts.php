<?php
// includes/exhibit_receipts.php
//
// Shared logic for rendering exhibit book-in/book-out receipts, and for
// saving a snapshot of one to disk (plus a DB record) at the moment it's
// generated, so it can be viewed again later exactly as it looked then -
// rather than recomputed live from whatever the exhibit's data has since
// become (see cargo_hold/view_receipt.php).

require_once __DIR__ . '/settings.php';

// Fetches the exhibit rows needed to render a receipt of $receiptType for
// the given exhibit ids. Mirrors the query cargo_hold/exhibit_receipt.php
// has always used.
function fetch_exhibit_receipt_rows(mysqli $conn, array $exhibitIds, string $receiptType): array
{
    $ids = implode(',', array_map('intval', $exhibitIds));
    if ($ids === '') {
        return [];
    }

    if ($receiptType === 'in') {
        $query = "
            SELECT
                e.exhibit_id,
                e.exhibit_ref,
                e.item_description,
                e.bag_number,
                e.time_in,
                e.delivered_by,
                j.custom_ref,
                j.created_by
            FROM exhibits e
            JOIN jobs j ON e.job_id = j.job_id
            WHERE e.exhibit_id IN ($ids)
        ";
    } else {
        $query = "
            SELECT
                e.exhibit_id,
                e.exhibit_ref,
                e.item_description,
                e.time_in,
                e.time_out,
                el.location_name,
                j.custom_ref,
                j.created_by
            FROM exhibits e
            JOIN exhibit_locations el ON e.location_id = el.location_id
            JOIN jobs j ON e.job_id = j.job_id
            WHERE e.exhibit_id IN ($ids)
        ";
    }

    $result = $conn->query($query);
    if (!$result) {
        return [];
    }

    $exhibits = [];
    while ($row = $result->fetch_assoc()) {
        if ($receiptType === 'in') {
            $exhibits[] = [
                'exhibit_id'       => $row['exhibit_id'],
                'exhibit_ref'      => $row['exhibit_ref'] ?? '',
                'item_description' => $row['item_description'] ?? '',
                'bag_number'       => $row['bag_number'] ?? '',
                'time_in'          => $row['time_in'] ?? '',
                'delivered_by'     => $row['delivered_by'] ?? '',
                'custom_ref'       => $row['custom_ref'] ?? '',
                'created_by'       => $row['created_by'] ?? '',
            ];
        } else {
            $exhibits[] = [
                'exhibit_id'       => $row['exhibit_id'],
                'exhibit_ref'      => $row['exhibit_ref'] ?? '',
                'item_description' => $row['item_description'] ?? '',
                'time_in'          => $row['time_in'] ?? '',
                'time_out'         => !empty($row['time_out']) ? $row['time_out'] : ($row['location_name'] ?? ''),
                'custom_ref'       => $row['custom_ref'] ?? '',
                'created_by'       => $row['created_by'] ?? '',
            ];
        }
    }
    return $exhibits;
}

// Renders the full standalone receipt HTML page - identical markup to what
// cargo_hold/exhibit_receipt.php has always shown, just parameterized so it
// can also be written to disk as a snapshot. $extraLabel is the one bit of
// free-text tied to the event itself rather than to an exhibit row - who it
// was booked out to, or who returned it.
function render_exhibit_receipt_html(array $exhibits, string $receiptType, string $jobCustomRef, string $userName, string $extraLabel = ''): string
{
    $deliveredBy = ($receiptType === 'in') ? ($exhibits[0]['delivered_by'] ?? '') : '';
    $titleSuffix = $receiptType === 'out' ? '(Book Out)' : ($receiptType === 'return' ? '(Book Back In)' : '');
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Exhibit Receipt <?php echo $titleSuffix; ?></title>
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
            <h1>Exhibit Receipt <?php echo $titleSuffix; ?></h1>
            <div class="job-info">
                <strong>Job Number:</strong> <?php echo htmlspecialchars($jobCustomRef); ?>
            </div>
        </div>

        <div class="details">
            <?php if ($receiptType === 'in'): ?>
            <div><strong>Booked In By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Delivered By:</strong> <?php echo htmlspecialchars($deliveredBy); ?></div>
            <?php elseif ($receiptType === 'out'): ?>
            <div><strong>Booked Out By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Booked Out To:</strong> <?php echo htmlspecialchars($extraLabel); ?></div>
            <?php else: ?>
            <div><strong>Received By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Returned By:</strong> <?php echo htmlspecialchars($extraLabel); ?></div>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Exhibit Ref</th>
                <th>Description</th>
                <?php if ($receiptType === 'in'): ?>
                <th>Bag Number</th>
                <th>Time In</th>
                <?php elseif ($receiptType === 'out'): ?>
                <th>Time In</th>
                <th>Time Out / Location</th>
                <?php else: ?>
                <th>Time Out</th>
                <th>Returned At</th>
                <th>Location</th>
                <?php endif; ?>
            </tr>
            <?php foreach ($exhibits as $ex): ?>
            <tr>
                <td><?php echo htmlspecialchars($ex['exhibit_ref']); ?></td>
                <td><?php echo htmlspecialchars($ex['item_description']); ?></td>
                <?php if ($receiptType === 'in'): ?>
                <td><?php echo htmlspecialchars($ex['bag_number'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($ex['time_in']); ?></td>
                <?php elseif ($receiptType === 'out'): ?>
                <td><?php echo htmlspecialchars($ex['time_in']); ?></td>
                <td><?php echo htmlspecialchars($ex['time_out']); ?></td>
                <?php else: ?>
                <td><?php echo htmlspecialchars($ex['time_out'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($ex['returned_at'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($ex['location_name'] ?? ''); ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="signature-section">
            <p><strong>Signature (<?php echo $receiptType === 'in' ? 'Delivery' : ($receiptType === 'out' ? 'Book Out' : 'Return'); ?>):</strong></p>
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

// Renders and saves a receipt to disk for the given exhibits (fetched fresh
// from their current DB state), and records it in
// exhibit_receipts/exhibit_receipt_items so it can be found again later.
// Returns the new receipt_id, or null if nothing could be saved (e.g. none
// of the given exhibit ids exist). Suitable for 'in'/'out', where the
// exhibits' current row state *is* what the receipt should show - not for
// 'return', which needs to show the time_out value from just before it's
// cleared (see save_exhibit_receipt_with_rows()).
function save_exhibit_receipt(mysqli $conn, int $jobId, string $receiptType, array $exhibitIds, int $generatedBy, string $extraLabel = ''): ?int
{
    $exhibits = fetch_exhibit_receipt_rows($conn, $exhibitIds, $receiptType);
    if (empty($exhibits)) {
        return null;
    }

    $jobCustomRef = $exhibits[0]['custom_ref'];
    return save_exhibit_receipt_with_rows($conn, $jobId, $receiptType, $exhibits, $jobCustomRef, $generatedBy, $extraLabel);
}

// Same as save_exhibit_receipt(), but takes already-built exhibit rows
// instead of fetching them live - for 'return' receipts, where the caller
// (book_in_exhibits.php) has to capture each exhibit's time_out *before*
// clearing it, plus the new return timestamp/location that don't exist in
// the exhibits table at all until after the update.
function save_exhibit_receipt_with_rows(mysqli $conn, int $jobId, string $receiptType, array $exhibits, string $jobCustomRef, int $generatedBy, string $extraLabel = ''): ?int
{
    if (empty($exhibits)) {
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

    $html = render_exhibit_receipt_html($exhibits, $receiptType, $jobCustomRef, $userName, $extraLabel);

    $storage = get_storage_settings($conn);
    $dir = $storage['paths']['receipt_dir_fs'];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = 'receipt_' . $receiptType . '_' . $jobId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.html';
    $filePath = $dir . $filename;
    if (file_put_contents($filePath, $html) === false) {
        return null;
    }

    // booked_out_to also carries the "returned by" name on 'return'
    // receipts - same shape (one free-text name tied to the event), not
    // worth a dedicated column for.
    $stmt = $conn->prepare("
        INSERT INTO exhibit_receipts (job_id, receipt_type, booked_out_to, file_path, generated_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $extraLabelParam = $extraLabel !== '' ? $extraLabel : null;
    $stmt->bind_param("isssi", $jobId, $receiptType, $extraLabelParam, $filePath, $generatedBy);
    $stmt->execute();
    $receiptId = $conn->insert_id;
    $stmt->close();

    $itemStmt = $conn->prepare("INSERT INTO exhibit_receipt_items (receipt_id, exhibit_id) VALUES (?, ?)");
    foreach ($exhibits as $ex) {
        $exhibitId = (int) $ex['exhibit_id'];
        $itemStmt->bind_param("ii", $receiptId, $exhibitId);
        $itemStmt->execute();
    }
    $itemStmt->close();

    return $receiptId;
}

// Every saved receipt that covers any of the given exhibit ids, oldest
// first - for "View Receipt" links on job.php. A list rather than one per
// type, since an exhibit can now cycle through book-out/return more than
// once.
function get_receipts_for_exhibits(mysqli $conn, array $exhibitIds): array
{
    $ids = implode(',', array_map('intval', array_filter($exhibitIds)));
    if ($ids === '') {
        return [];
    }

    $result = $conn->query("
        SELECT eri.exhibit_id, er.receipt_id, er.receipt_type, er.generated_at
        FROM exhibit_receipt_items eri
        JOIN exhibit_receipts er ON eri.receipt_id = er.receipt_id
        WHERE eri.exhibit_id IN ($ids)
        ORDER BY er.generated_at ASC, er.receipt_id ASC
    ");

    $byExhibit = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $byExhibit[(int) $row['exhibit_id']][] = [
                'receipt_id'   => (int) $row['receipt_id'],
                'receipt_type' => $row['receipt_type'],
                'generated_at' => $row['generated_at'],
            ];
        }
    }
    return $byExhibit;
}
