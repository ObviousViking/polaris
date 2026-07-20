<?php
// Start the session only if not already active.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once('../db.php');
require_once '../includes/permissions.php';
require_permission($conn, 'exhibit_view');

// Ensure job_id and exhibit ids are provided.
if (!isset($_GET['job_id'])) {
    die("Job ID not specified.");
}
$job_id = intval($_GET['job_id']);

if (!isset($_GET['ids'])) {
    die("No exhibit IDs specified.");
}
$ids = $_GET['ids'];
if (!preg_match('/^[0-9,]+$/', $ids)) {
    die("Invalid exhibit IDs.");
}

// Determine the receipt type: default to "in", or if type=out then it's a book-out receipt.
$receiptType = (isset($_GET['type']) && $_GET['type'] === 'out') ? 'out' : 'in';

// For a book-out receipt, get the "Booked Out To" value.
$bookedOutTo = ($receiptType === 'out' && isset($_GET['booked_out_to'])) ? $_GET['booked_out_to'] : '';

// Build the SQL query based on receipt type.
if ($receiptType === 'in') {
    // For booking in, we need bag_number and delivered_by.
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
    // For booking out, we need time_out and the location name.
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
    die("Database error: " . $conn->error);
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
            'created_by'       => $row['created_by'] ?? ''
        ];
    } else {
        $exhibits[] = [
            'exhibit_id'       => $row['exhibit_id'],
            'exhibit_ref'      => $row['exhibit_ref'] ?? '',
            'item_description' => $row['item_description'] ?? '',
            'time_in'          => $row['time_in'] ?? '',
            // If time_out is not set, show location_name instead.
            'time_out'         => !empty($row['time_out']) ? $row['time_out'] : ($row['location_name'] ?? ''),
            'custom_ref'       => $row['custom_ref'] ?? '',
            'created_by'       => $row['created_by'] ?? ''
        ];
    }
}
if (empty($exhibits)) {
    die("No exhibits found.");
}

// Use the first exhibit's job info for the Job Number.
$jobCustomRef = $exhibits[0]['custom_ref'];

// Determine "Booked By" (for Book In) or "Booked Out By" (for Book Out) from the current session user.
$currentUserId = $_SESSION['user_id'];
$queryUser = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE id = " . intval($currentUserId);
$resUser = $conn->query($queryUser);
$userName = $currentUserId; // fallback
if ($resUser && $rowUser = $resUser->fetch_assoc()) {
    $userName = $rowUser['full_name'];
}

// For Book In receipts, get Delivered By from the exhibit data.
$deliveredBy = ($receiptType === 'in') ? $exhibits[0]['delivered_by'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Exhibit Receipt <?php echo ($receiptType === 'out') ? '(Book Out)' : ''; ?></title>
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
            <h1>Exhibit Receipt <?php echo ($receiptType === 'out') ? '(Book Out)' : ''; ?></h1>
            <div class="job-info">
                <strong>Job Number:</strong> <?php echo htmlspecialchars($jobCustomRef); ?>
            </div>
        </div>

        <div class="details">
            <?php if ($receiptType === 'in'): ?>
            <div><strong>Booked In By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Delivered By:</strong> <?php echo htmlspecialchars($deliveredBy); ?></div>
            <?php else: ?>
            <div><strong>Booked Out By:</strong> <?php echo htmlspecialchars($userName); ?></div>
            <div><strong>Booked Out To:</strong> <?php echo htmlspecialchars($bookedOutTo); ?></div>
            <?php endif; ?>
        </div>

        <table>
            <tr>
                <th>Exhibit Ref</th>
                <th>Description</th>
                <?php if ($receiptType === 'in'): ?>
                <th>Bag Number</th>
                <th>Time In</th>
                <?php else: ?>
                <th>Time In</th>
                <th>Time Out / Location</th>
                <?php endif; ?>
            </tr>
            <?php foreach ($exhibits as $ex): ?>
            <tr>
                <td><?php echo htmlspecialchars($ex['exhibit_ref']); ?></td>
                <td><?php echo htmlspecialchars($ex['item_description']); ?></td>
                <?php if ($receiptType === 'in'): ?>
                <td><?php echo htmlspecialchars($ex['bag_number'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($ex['time_in']); ?></td>
                <?php else: ?>
                <td><?php echo htmlspecialchars($ex['time_in']); ?></td>
                <td><?php echo htmlspecialchars($ex['time_out']); ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="signature-section">
            <p><strong>Signature (<?php echo ($receiptType === 'in') ? 'Delivery' : 'Book Out'; ?>):</strong></p>
            <div class="signature-line"></div>
        </div>

        <div class="no-print">
            <button onclick="window.print();">Print Receipt</button>
        </div>
    </div>
</body>

</html>