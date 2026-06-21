<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


echo "<h3>DB Connected OK</h3>";

$tables = ['users','contracts','suppliers','payment_approval_settings','user_page_order','pages','user_permissions'];

foreach ($tables as $table) {
    try {
        $res = $conn->query("SELECT COUNT(*) c FROM `$table`");
        $row = $res->fetch_assoc();
        echo $table . " = " . $row['c'] . "<br>";
    } catch (Throwable $e) {
        echo "<b style='color:red'>Missing/Error in $table:</b> " . $e->getMessage() . "<br>";
    }
}