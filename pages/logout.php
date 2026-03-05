<?php
// pages/logout.php
logout($config);
header('Location: index.php?page=login');
exit;
