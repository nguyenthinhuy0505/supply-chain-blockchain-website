<?php
// Bắt đầu session
session_start();

// Hủy tất cả session
session_unset();
session_destroy();

// Chuyển hướng về trang index
header("Location: index.php");
exit();
?>