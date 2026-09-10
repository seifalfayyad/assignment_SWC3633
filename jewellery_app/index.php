<?php
require_once 'config.php';

$db_orders = [];
$gold_price_usd = 0.00;
$myr_exchange_rate = 0.00;
$api_errors = [];

// Database Connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Fetch order records using JOIN
$sql = "SELECT Orders.order_id, Customers.customer_name, GoldProducts.product_name, 
               GoldProducts.weight_g, GoldProducts.purity, Orders.quantity, Orders.order_date 
        FROM Orders
        JOIN Customers ON Orders.customer_id = Customers.customer_id
        JOIN GoldProducts ON Orders.product_id = GoldProducts.product_id";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $db_orders[] = $row;
    }
}

// Fetch API 1: MetalpriceAPI 
$gold_url = "https://api.metalpriceapi.com/v1/latest?api_key=" . urlencode(METALPRICE_API_KEY) . "&base=USD&currencies=XAU";
$gold_ch = curl_init();
curl_setopt($gold_ch, CURLOPT_URL, $gold_url);
curl_setopt($gold_ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($gold_ch, CURLOPT_TIMEOUT, 10);
$gold_res = curl_exec($gold_ch);

if (curl_errno($gold_ch) || !$gold_res) {
    $api_errors[] = "Failed to fetch gold price from MetalpriceAPI.";
} else {
    $gold_data = json_decode($gold_res, true);
    // MetalpriceAPI usually provides currency value relative to base (e.g. 1 USD = X XAU)
    // To get USD per Troy Ounce, we calculate 1 / XAU rate value
    if (isset($gold_data['rates']['XAU'])) {
        $gold_price_usd = 1 / $gold_data['rates']['XAU'];
    } else {
        $api_errors[] = "Invalid structure returned from MetalpriceAPI.";
    }
}
curl_close($gold_ch);

//  AbstractAPI (USD to MYR Exchange Rate)
$ex_url = "https://exchange-rates.abstractapi.com/v1/live/?api_key=" . urlencode(ABSTRACT_API_KEY) . "&base=USD&target=MYR";
$ex_ch = curl_init();
curl_setopt($ex_ch, CURLOPT_URL, $ex_url);
curl_setopt($ex_ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ex_ch, CURLOPT_TIMEOUT, 10);
$ex_res = curl_exec($ex_ch);

if (curl_errno($ex_ch) || !$ex_res) {
    $api_errors[] = "Failed to fetch currency rates from AbstractAPI.";
} else {
    $ex_data = json_decode($ex_res, true);
    if (isset($ex_data['exchange_rates']['MYR'])) {
        $myr_exchange_rate = $ex_data['exchange_rates']['MYR'];
    } else {
        $api_errors[] = "Invalid structure returned from AbstractAPI.";
    }
}
curl_close($ex_ch);

// Calculate standard Pure Gold Price per gram in MYR
$pure_gold_price_myr = 0.00;
if ($gold_price_usd > 0 && $myr_exchange_rate > 0) {
    $pure_gold_price_myr = ($gold_price_usd * $myr_exchange_rate) / 31.1035;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gold Jewellery Orders Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        .error-box { background-color: #fdd; color: #900; padding: 10px; border: 1px solid #900; margin-bottom: 20px; }
        .meta-info { margin-bottom: 15px; padding: 10px; background-color: #fafafa; border: 1px solid #ddd; }
    </style>
</head>
<body>

    <h1>Gold Jewellery Customer Orders</h1>

    <!-- Basic Error Handling Notification Summary -->
    <?php if (!empty($api_errors)): ?>
        <div class="error-box">
            <strong>System Alert:</strong>
            <ul>
                <?php foreach ($api_errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Context Metadata and API Sourcing Context -->
    <div class="meta-info">
        <p><strong>API 1 (MetalpriceAPI):</strong> Latest available gold spot price: <strong>$<?php echo number_format($gold_price_usd, 2); ?> USD</strong> / troy ounce</p>
        <p><strong>API 2 (AbstractAPI):</strong> Latest available USD-to-MYR exchange rate: <strong>RM <?php echo number_format($myr_exchange_rate, 4); ?></strong></p>
        <p><strong>Calculated Value Basis:</strong> Pure Gold Price: RM <?php echo number_format($pure_gold_price_myr, 2); ?> / gram</p>
    </div>

    <!-- Main Output Grid Component -->
    <table>
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Customer Name</th>
                <th>Product Name</th>
                <th>Weight (g)</th>
                <th>Gold Purity</th>
                <th>Quantity</th>
                <th>Total Weight (g)</th>
                <th>Order Date</th>
                <th>Estimated Gold Value (MYR)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($db_orders)): ?>
                <?php foreach ($db_orders as $order): 
                    // Perform specific row calculations matching formulas
                    $total_weight = $order['weight_g'] * $order['quantity'];
                    $purity_factor = $order['purity'] / 1000;
                    $estimated_value = $pure_gold_price_myr * $total_weight * $purity_factor;
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($order['product_name']); ?></td>
                        <td><?php echo number_format($order['weight_g'], 2); ?></td>
                        <td><?php echo htmlspecialchars($order['purity']); ?></td>
                        <td><?php echo htmlspecialchars($order['quantity']); ?></td>
                        <td><?php echo number_format($total_weight, 2); ?></td>
                        <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                        <td>
                            <?php if ($pure_gold_price_myr > 0): ?>
                                <strong>RM <?php echo number_format($estimated_value, 2); ?></strong>
                            <?php else: ?>
                                Unavailable
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">No records available or calculation data missing due to upstream API disconnects.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>
<?php $conn->close(); ?>

