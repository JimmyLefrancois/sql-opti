<?php
// Constants for the database connection
define('DB_TYPE', 'mssql'); // Supported: mysql, mssql
// Other constants...

function connectDatabase() {
    $dsn = 'sqlsrv:Server=host,port;Database=dbname'; // Updated for MSSQL
    try {
        $pdo = new PDO($dsn, 'username', 'password');
        // More connection logic...
    } catch (PDOException $e) {
        // Handle exception...
    }
}

function setupTables() {
    $sql = "CREATE TABLE example_table (
        id INT IDENTITY(1,1) PRIMARY KEY,
        created_at DATETIME DEFAULT GETDATE()
        // More columns...
    )"; // Updated to MSSQL syntax
    // Execute the SQL statement...
}

function testUpdateOptimise() {
    $sql = "CREATE TABLE #temp_updates (
        id INT,
        value VARCHAR(255)
    )"; // Local temp table for MSSQL
    // Execute and manage the table...
    $sql = "UPDATE dest
              SET dest.value = source.value
              FROM dest
              INNER JOIN source ON dest.id = source.id"; // UPDATE syntax remains the same
    // Execute the update statement...
}

function cleanTables() {
    $tables = ['example_table', '#temp_updates']; // Adjust as needed
    foreach($tables as $table) {
        if (/* Check if table exists */) {
            $sql = "DELETE FROM " . $table; // Use DELETE instead of TRUNCATE
            // Execute delete statement...
        }
    }
}