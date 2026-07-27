<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once('../includes/integrity.php');
require_once '../includes/permissions.php';
require_permission($conn, 'asset_checkout');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['checkout_asset'])) {
        $asset_id = intval($_POST['asset_id']);
        $checked_out_to = intval($_POST['checked_out_to']);
        $due_back_at = trim($_POST['due_back_at'] ?? '');
        $condition_out = trim($_POST['condition_out'] ?? '');
        $checked_out_by = (int) $_SESSION['user_id'];

        if ($asset_id === 0 || $checked_out_to === 0) {
            $message = "Please select both an asset and who it's going to.";
        } else {
            $dupStmt = $conn->prepare("SELECT COUNT(*) FROM asset_checkouts WHERE asset_id = ? AND checked_in_at IS NULL");
            $dupStmt->bind_param("i", $asset_id);
            $dupStmt->execute();
            $dupStmt->bind_result($alreadyOut);
            $dupStmt->fetch();
            $dupStmt->close();

            if ($alreadyOut > 0) {
                $message = "That asset is already checked out.";
            } else {
                $dueBackParam = $due_back_at !== '' ? $due_back_at : null;
                $stmt = $conn->prepare("INSERT INTO asset_checkouts (asset_id, checked_out_to, checked_out_by, due_back_at, condition_out) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiss", $asset_id, $checked_out_to, $checked_out_by, $dueBackParam, $condition_out);
                if ($stmt->execute()) {
                    $toStmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
                    $toStmt->bind_param("i", $checked_out_to);
                    $toStmt->execute();
                    $toStmt->bind_result($toName);
                    $toStmt->fetch();
                    $toStmt->close();

                    $changes = json_encode(['checked_out_to' => $toName, 'due_back_at' => $dueBackParam, 'condition_out' => $condition_out]);
                    insert_history_row($conn, 'asset_history', $asset_id, 'CHECKOUT', $checked_out_by, $changes);

                    $availStmt = $conn->prepare("UPDATE assets SET availability = 'Deployed' WHERE id = ?");
                    $availStmt->bind_param("i", $asset_id);
                    $availStmt->execute();
                    $availStmt->close();

                    $message = "Asset checked out.";
                } else {
                    $message = "Error checking out asset: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['checkin_asset'])) {
        $checkout_id = intval($_POST['checkout_id']);
        $condition_in = trim($_POST['condition_in'] ?? '');
        $checked_in_by = (int) $_SESSION['user_id'];

        $lookupStmt = $conn->prepare("SELECT asset_id FROM asset_checkouts WHERE checkout_id = ? AND checked_in_at IS NULL");
        $lookupStmt->bind_param("i", $checkout_id);
        $lookupStmt->execute();
        $lookupStmt->bind_result($asset_id);
        if ($lookupStmt->fetch()) {
            $lookupStmt->close();

            $stmt = $conn->prepare("UPDATE asset_checkouts SET checked_in_at = NOW(), checked_in_by = ?, condition_in = ? WHERE checkout_id = ?");
            $stmt->bind_param("isi", $checked_in_by, $condition_in, $checkout_id);
            if ($stmt->execute()) {
                insert_history_row($conn, 'asset_history', $asset_id, 'CHECKIN', $checked_in_by, json_encode(['condition_in' => $condition_in]));

                $availStmt = $conn->prepare("UPDATE assets SET availability = 'Not Deployed' WHERE id = ?");
                $availStmt->bind_param("i", $asset_id);
                $availStmt->execute();
                $availStmt->close();

                $message = "Asset checked in.";
            } else {
                $message = "Error checking in asset: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $lookupStmt->close();
            $message = "That checkout record wasn't found or is already checked in.";
        }
    }
}

// Assets available to check out: not deleted, not destroyed/out of
// service, and with no open checkout row.
$availableAssets = [];
$res = $conn->query("
    SELECT a.id, a.asset_number, a.friendly_name
    FROM assets a
    LEFT JOIN asset_checkouts c ON c.asset_id = a.id AND c.checked_in_at IS NULL
    WHERE a.deleted_at IS NULL
      AND a.availability NOT IN ('Destroyed', 'Out Of Service')
      AND c.checkout_id IS NULL
    ORDER BY a.asset_number
");
while ($row = $res->fetch_assoc()) {
    $availableAssets[] = $row;
}
$res->free();

$activeUsers = [];
$res = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE active = 1 ORDER BY first_name, last_name");
while ($row = $res->fetch_assoc()) {
    $activeUsers[] = $row;
}
$res->free();

$openCheckouts = [];
$res = $conn->query("
    SELECT c.checkout_id, c.due_back_at, c.checked_out_at, a.asset_number, a.friendly_name,
           CONCAT(u.first_name, ' ', u.last_name) AS checked_out_to_name
    FROM asset_checkouts c
    JOIN assets a ON a.id = c.asset_id
    JOIN users u ON u.id = c.checked_out_to
    WHERE c.checked_in_at IS NULL
    ORDER BY c.checked_out_at DESC
");
while ($row = $res->fetch_assoc()) {
    $openCheckouts[] = $row;
}
$res->free();
?>
<style>
    body {
        margin: 0;
        font-family: Arial, sans-serif;
        background: var(--polaris-bg);
        color: var(--polaris-text);
        padding-top: <?php echo $embedded ? '0' : '120px'; ?>;
    }

    .content-wrapper {
        max-width: 1100px;
        margin: 0 auto;
        padding: 20px;
    }

    h2 {
        margin-top: 0;
    }

    .card {
        background: var(--polaris-surface);
        border-radius: 5px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-top: 10px;
        font-weight: bold;
        color: var(--polaris-text-dim);
        font-size: 13px;
    }

    input[type="text"],
    input[type="date"],
    select {
        width: 100%;
        padding: 6px;
        margin-top: 4px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
        border-radius: 3px;
        box-sizing: border-box;
    }

    .form-row {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .form-row>div {
        flex: 1 1 200px;
    }

    .action-btn {
        margin-top: 15px;
        padding: 6px 12px;
        border: none;
        border-radius: 3px;
        cursor: pointer;
        font-size: 13px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 15px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background: var(--polaris-surface);
    }

    th,
    td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid var(--polaris-border);
        font-size: 13px;
        vertical-align: top;
    }

    th {
        background: var(--polaris-divider);
        white-space: nowrap;
    }

    .overdue {
        color: var(--polaris-danger);
        font-weight: bold;
    }

    .empty-message {
        color: var(--polaris-text-muted);
        font-style: italic;
        padding: 10px 0;
    }

    .checkin-form {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .checkin-form input[type="text"] {
        margin-top: 0;
        width: 160px;
    }
</style>

<div class="content-wrapper">
    <h2>Asset Checkout</h2>

    <?php if (!empty($message)): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Check Out an Asset</h3>
        <?php if (empty($availableAssets)): ?>
        <p class="empty-message">No assets currently available to check out.</p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="checkout_asset" value="1">
            <div class="form-row">
                <div>
                    <label for="asset_id">Asset</label>
                    <select name="asset_id" id="asset_id" required>
                        <option value="">Select asset</option>
                        <?php foreach ($availableAssets as $a): ?>
                        <option value="<?php echo $a['id']; ?>">
                            <?php echo htmlspecialchars($a['asset_number'] . ' - ' . $a['friendly_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="checked_out_to">Checked Out To</label>
                    <select name="checked_out_to" id="checked_out_to" required>
                        <option value="">Select user</option>
                        <?php foreach ($activeUsers as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="due_back_at">Due Back</label>
                    <input type="date" name="due_back_at" id="due_back_at">
                </div>
                <div>
                    <label for="condition_out">Condition Out</label>
                    <input type="text" name="condition_out" id="condition_out" placeholder="Optional notes">
                </div>
            </div>
            <button type="submit" class="action-btn">Check Out</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Currently Checked Out</h3>
        <?php if (empty($openCheckouts)): ?>
        <p class="empty-message">No assets are currently checked out.</p>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <tr>
                    <th>Asset</th>
                    <th>Checked Out To</th>
                    <th>Checked Out</th>
                    <th>Due Back</th>
                    <th>Check In</th>
                </tr>
                <?php foreach ($openCheckouts as $c):
                    $isOverdue = $c['due_back_at'] !== null && strtotime($c['due_back_at']) < time();
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['asset_number'] . ' - ' . $c['friendly_name']); ?></td>
                    <td><?php echo htmlspecialchars($c['checked_out_to_name']); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($c['checked_out_at'])); ?></td>
                    <td class="<?php echo $isOverdue ? 'overdue' : ''; ?>">
                        <?php echo $c['due_back_at'] ? date('d/m/Y', strtotime($c['due_back_at'])) . ($isOverdue ? ' (overdue)' : '') : '-'; ?>
                    </td>
                    <td>
                        <form method="post" class="checkin-form">
                            <input type="hidden" name="checkin_asset" value="1">
                            <input type="hidden" name="checkout_id" value="<?php echo $c['checkout_id']; ?>">
                            <input type="text" name="condition_in" placeholder="Condition in (optional)">
                            <button type="submit" class="action-btn">Check In</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$embedded): ?>
    <a href="lh_dashboard.php" class="action-btn">&larr; Back to Asset Management</a>
    <?php endif; ?>
</div>
