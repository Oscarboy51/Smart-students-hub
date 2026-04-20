<?php
include 'db.php';

$productName = $_POST['product_name'];
$category = $_POST['category'];
$description = $_POST['description'];
$price = $_POST['price'];
$phone = $_POST['phone'];
$stock = $_POST['stock_status'];
$sellerId = $_POST['seller_id'];
$image = $_POST['product_image'];

$sql = "INSERT INTO products (
    seller_id,
    product_name,
    category,
    description,
    price,
    phone,
    stock_status,
    product_image
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    'isssdsss',
    $sellerId,
    $productName,
    $category,
    $description,
    $price,
    $phone,
    $stock,
    $image
);
$stmt->execute();
?>