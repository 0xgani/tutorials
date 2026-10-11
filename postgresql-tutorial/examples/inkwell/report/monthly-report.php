<?php
// Inkwell's monthly sales report, for the command line (chapter 24).
// It connects as inkwell_report, which may read the reporting schema and nothing else that matters.
//
//   php monthly-report.php                 a text report
//   php monthly-report.php --csv           monthly revenue as CSV, for a spreadsheet
//   php monthly-report.php --refresh       refresh the product sales first (MAINTAIN privilege, 17+)
//   php monthly-report.php --top=10        how many products to list (default 5)
//
// Connection: INKWELL_REPORT_DSN (default "pgsql:dbname=inkwell_dev"); host, port and password fall back to the
// standard PGHOST, PGPORT and PGPASSWORD variables or ~/.pgpass, as in psql (chapter 22).

declare(strict_types=1);

$options = getopt('', ['csv', 'refresh', 'top:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "usage: php monthly-report.php [--csv] [--refresh] [--top=N]\n");
    exit(0);
}
$top = (int) ($options['top'] ?? 5);
if ($top < 1 || $top > 100) {
    fwrite(STDERR, "--top must be between 1 and 100\n");
    exit(2);
}

try {
    $db = new PDO(
        getenv('INKWELL_REPORT_DSN') ?: 'pgsql:dbname=inkwell_dev',
        getenv('INKWELL_REPORT_USER') ?: 'inkwell_report',
        getenv('INKWELL_REPORT_PASSWORD') ?: null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );
    $db->exec("SET application_name = 'inkwell-report'");

    if (isset($options['refresh'])) {
        // The role's sessions are read-only by default (db/roles.sql); this one transaction may write.
        // CONCURRENTLY: readers of the old contents aren't blocked while it runs (chapter 20).
        $db->exec('START TRANSACTION READ WRITE');
        $db->exec('REFRESH MATERIALIZED VIEW CONCURRENTLY reporting.product_sales');
        $db->exec('COMMIT');
    }

    $months = $db->query(
        "SELECT to_char(month, 'YYYY-MM') AS month, orders, customers, revenue
         FROM reporting.monthly_revenue
         ORDER BY month"
    )->fetchAll();

    if (isset($options['csv'])) {
        $out = fopen('php://stdout', 'w');
        fputcsv($out, ['month', 'orders', 'customers', 'revenue'], ',', '"', '');
        foreach ($months as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        exit(0);
    }

    // Totals are summed by PostgreSQL: revenue arrives as a string ("119.00"), and adding the strings as PHP
    // floats would invite rounding errors.
    $total = $db->query('SELECT sum(orders) AS orders, sum(revenue) AS revenue FROM reporting.monthly_revenue')
                ->fetch();

    $products = $db->prepare(
        'SELECT product_name, sum(units) AS units, sum(revenue) AS revenue
         FROM reporting.product_sales
         GROUP BY product_id, product_name
         ORDER BY revenue DESC, product_name
         LIMIT :top'
    );
    $products->bindValue('top', $top, PDO::PARAM_INT);
    $products->execute();

    $generated = $db->query("SELECT to_char(now(), 'YYYY-MM-DD HH24:MI TZ') AS now")->fetchColumn();
} catch (PDOException $e) {
    // getCode() is the SQLSTATE (chapter 22).
    fwrite(STDERR, "report failed ({$e->getCode()}): {$e->getMessage()}\n");
    exit(1);
}

printf("Inkwell sales report, generated %s\n\n", $generated);
printf("%-8s %7s %10s %12s\n", 'Month', 'Orders', 'Customers', 'Revenue');
foreach ($months as $row) {
    printf("%-8s %7d %10d %12s\n", $row['month'], $row['orders'], $row['customers'], $row['revenue']);
}
printf("%-8s %7d %10s %12s\n\n", 'Total', $total['orders'], '', $total['revenue']);

printf("Top %d products by revenue\n", $top);
printf("%-26s %6s %12s\n", 'Product', 'Units', 'Revenue');
foreach ($products as $row) {
    printf("%-26s %6d %12s\n", $row['product_name'], $row['units'], $row['revenue']);
}
