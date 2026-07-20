<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
require_once('../db.php');
require_once '../includes/audit.php';
require_once '../includes/permissions.php';
require_permission($conn, 'manage_lookups');
$embedded = isset($_GET['embedded']);
if ($embedded) {
    require_once '../includes/embedded_header.php';
} else {
    require_once('../header.php');
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = strtolower(trim($_POST['email']));
    $phone = trim($_POST['phone']);
    $organisation = trim($_POST['organisation']);
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;

    if ($name === '' || $email === '' || $organisation === '') {
        $message = "Name, Email and Organisation are required.";
    } else {
        $dupCheck = $conn->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(email) = ? AND customer_id != ?");
        $dupCheck->bind_param("si", $email, $customer_id);
        $dupCheck->execute();
        $dupCheck->bind_result($count);
        $dupCheck->fetch();
        $dupCheck->close();

        if ($count > 0) {
            $message = "Duplicate email found.";
        } else {
            if ($customer_id > 0) {
                $stmt = $conn->prepare("UPDATE customers SET name = ?, email = ?, phone_number = ?, organisation = ? WHERE customer_id = ?");
                $stmt->bind_param("ssssi", $name, $email, $phone, $organisation, $customer_id);
                $stmt->execute();
                $stmt->close();
                log_audit_event($conn, 'customer', $customer_id, 'UPDATE', (int) $_SESSION['user_id'], json_encode(['name' => $name, 'email' => $email, 'organisation' => $organisation]));
                $message = "Customer updated.";
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (name, email, phone_number, organisation) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $email, $phone, $organisation);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                log_audit_event($conn, 'customer', $newId, 'CREATE', (int) $_SESSION['user_id'], json_encode(['name' => $name, 'email' => $email, 'organisation' => $organisation]));
                $message = "New customer added.";
            }
        }
    }
}

$customers = [];
$res = $conn->query("SELECT * FROM customers ORDER BY name");
while ($row = $res->fetch_assoc()) {
    $customers[] = [
        'customer_id' => $row['customer_id'],
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? '',
        'phone' => $row['phone_number'] ?? '',
        'organisation' => $row['organisation'] ?? ''
    ];
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

    .container {
        max-width: 900px;
        margin: 20px auto;
        background: var(--polaris-surface);
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(255, 255, 255, 0.1);
    }

    h2 {
        text-align: center;
        margin-bottom: 20px;
    }

    input[type="text"],
    input[type="email"] {
        width: 100%;
        padding: 8px;
        margin-bottom: 10px;
        background: var(--polaris-bg);
        border: 1px solid var(--polaris-border);
        color: var(--polaris-text);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    th,
    td {
        border: 1px solid var(--polaris-border);
        padding: 8px;
        text-align: left;
    }

    th {
        background: var(--polaris-divider);
    }

    .action-btn {
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 3px;
    }

    .action-btn:hover {
        background: var(--polaris-accent-hover);
    }

    .message {
        margin-bottom: 10px;
        padding: 10px;
        background: var(--polaris-divider);
        border-left: 4px solid var(--polaris-accent);
    }

    .back-btn {
        display: inline-block;
        padding: 5px 10px;
        background: var(--polaris-accent);
        color: var(--polaris-text);
        border: none;
        border-radius: 3px;
        text-decoration: none;
        text-align: center;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.3s ease;
        margin-bottom: 20px;
    }

    .back-btn:hover {
        background: var(--polaris-accent-hover);
    }
    </style>
    <script>
    function filterCustomers() {
        const input = document.getElementById("filter").value.toUpperCase();
        const rows = document.querySelectorAll("#customersTable tbody tr");
        rows.forEach(row => {
            const cells = row.querySelectorAll("td");
            let match = false;
            cells.forEach(cell => {
                if (cell.innerText.toUpperCase().includes(input)) {
                    match = true;
                }
            });
            row.style.display = match ? "" : "none";
        });
    }

    function populateForm(id, name, email, phone, organisation) {
        document.getElementById("customer_id").value = id;
        document.getElementById("name").value = name;
        document.getElementById("email").value = email;
        document.getElementById("phone").value = phone;
        document.getElementById("organisation").value = organisation;
    }
    </script>

    <div class="container">
        <h2>Manage Customers</h2>

        <?php if (!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="post" action="manage_customers.php<?php echo $embedded ? '?embedded=1' : ''; ?>">
            <input type="hidden" name="customer_id" id="customer_id">
            <label>Name</label>
            <input type="text" name="name" id="name" required>
            <label>Email</label>
            <input type="email" name="email" id="email" required>
            <label>Phone</label>
            <input type="text" name="phone" id="phone">
            <label>Organisation</label>
            <input type="text" name="organisation" id="organisation" required>
            <button type="submit" class="action-btn">Save</button>
        </form>

        <label for="filter">Filter Customers</label>
        <input type="text" id="filter" oninput="filterCustomers();">

        <table id="customersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Organisation</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $cust): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cust['name']); ?></td>
                    <td><?php echo htmlspecialchars($cust['email']); ?></td>
                    <td><?php echo htmlspecialchars($cust['phone']); ?></td>
                    <td><?php echo htmlspecialchars($cust['organisation']); ?></td>
                    <td>
                        <button class="action-btn"
                            onclick="populateForm('<?php echo $cust['customer_id']; ?>', '<?php echo htmlspecialchars($cust['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cust['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cust['phone'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cust['organisation'], ENT_QUOTES); ?>')">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <br>
        <?php if (!$embedded): ?>
        <a href="/cargo_hold/manage_system_details.php" class="back-btn">Go Back</a>
        <?php endif; ?>
    </div>
</body>

</html>