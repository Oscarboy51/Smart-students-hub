<?php
include 'db.php';

$sql = "SELECT 
    products.*, 
    users.username AS seller_name 
FROM products
INNER JOIN users ON products.seller_id = users.id
ORDER BY products.created_at DESC";

$result = $conn->query($sql);

while($row = $result->fetch_assoc()) {
    echo '<div class="prod-card">';
    echo '<img src="'.$row['product_image'].'">';
    echo '<h3>'.$row['product_name'].'</h3>';
    echo '<p>GHS '.$row['price'].'</p>';
    echo '<p>'.$row['seller_name'].'</p>';
    echo '</div>';
}
?>