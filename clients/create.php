<?php
// Include your database connection
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $firstName = $_POST['first_name'];
    $lastName = $_POST['last_name'];
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $package = $_POST['package'];
    $expiryDate = $_POST['expiry_date'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $comment = $_POST['comment'];

    $sql = "INSERT INTO users (type, first_name, last_name, username, password, package, expiry_date, phone, email, address, comment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssssss", $type, $firstName, $lastName, $username, $password, $package, $expiryDate, $phone, $email, $address, $comment);

    if ($stmt->execute()) {
        echo "<script>alert('Client created successfully!'); window.location.href='/users';</script>";
    } else {
        echo "<script>alert('Error creating client: " . $conn->error . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Client</title>
    <style>
        body {
            background-color: #111;
            color: #f1f1f1;
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding-top: 40px;
        }
        form {
            background-color: #1c1c1c;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 0 15px rgba(0,0,0,0.6);
        }
        h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #00b894;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #ccc;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: none;
            border-radius: 6px;
            background-color: #2d2d2d;
            color: #fff;
        }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid #00b894;
        }
        .row {
            display: flex;
            gap: 20px;
        }
        .col {
            flex: 1;
        }
        button {
            background-color: #00b894;
            color: #fff;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            margin-right: 10px;
        }
        button:hover {
            background-color: #019c7c;
        }
        .cancel-btn {
            background-color: #555;
        }
        .cancel-btn:hover {
            background-color: #444;
        }
    </style>
</head>
<body>
    <form method="POST" action="">
        <h2>Create User</h2>

        <label>Type *</label>
        <select name="type" required>
            <option value="">Select an option</option>
            <option value="Client">Client</option>
            <option value="Admin">Admin</option>
        </select>

        <div class="row">
            <div class="col">
                <label>First Name</label>
                <input type="text" name="first_name">
            </div>
            <div class="col">
                <label>Last Name</label>
                <input type="text" name="last_name">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label>Username *</label>
                <input type="text" name="username" required>
            </div>
            <div class="col">
                <label>Password *</label>
                <input type="password" name="password" required>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label>Package *</label>
                <select name="package" required>
                    <option value="">Select an option</option>
                    <option value="1 Mbps (30 Days)">1 Mbps (30 Days)</option>
                    <option value="2 Mbps (30 Days)">2 Mbps (30 Days)</option>
                    <option value="Daily Unlimited">Daily Unlimited</option>
                    <option value="Hourly">Hourly</option>
                </select>
            </div>
            <div class="col">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date">
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label>Phone Number</label>
                <input type="text" name="phone">
            </div>
            <div class="col">
                <label>Email Address</label>
                <input type="email" name="email">
            </div>
        </div>

        <label>Address</label>
        <input type="text" name="address" placeholder="123 Main St, City, Country">

        <label>Comment</label>
        <textarea name="comment" rows="3"></textarea>

        <button type="submit">Create</button>
        <button type="button" class="cancel-btn" onclick="window.location.href='/users';">Cancel</button>
    </form>
</body>
</html>
