<?php
// SQL Optimization Benchmarking

// Set the current time
$current_time = new DateTime();

// Function to execute SQL queries and measure execution time
function benchmark_sql($query, $pdo) {
    $start_time = microtime(true);
    $pdo->exec($query);
    $end_time = microtime(true);

    return $end_time - $start_time;
}

// Example usage
try {
    // Database connection
    $pdo = new PDO('mysql:host=localhost;dbname=test', 'username', 'password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL queries to benchmark
    $queries = [
        "CREATE TABLE IF NOT EXISTS test (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))",
        "INSERT INTO test (name) VALUES ('Sample Name')",
        "SELECT * FROM test",
    ];

    // Benchmark each query
    foreach ($queries as $query) {
        $duration = benchmark_sql($query, $pdo);
        echo "Query: $query - Duration: {$duration} seconds\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>