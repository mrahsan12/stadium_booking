<?php

$conn = mysqli_connect("localhost", "root", "", "arenahub");

$data = json_decode(file_get_contents("php://input"), true);

$name = $data['name'];
$email = $data['email'];
$password = $data['password'];

$sql = "INSERT INTO users(name, email, password)
VALUES ('$name', '$email', '$password')";

if(mysqli_query($conn, $sql)){
    echo json_encode([
        "status" => "success",
        "message" => "User registered successfully"
    ]);
} else {
    echo json_encode([
        "status" => "error"
    ]);
}
?>
