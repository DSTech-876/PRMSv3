<?php
use Dompdf\Dompdf;
use Dompdf\Options;

session_start();
require_once $_SERVER['DOCUMENT_ROOT']."/config/db.php";
require_once $_SERVER['DOCUMENT_ROOT']."/config/helper.php";
require_once $_SERVER['DOCUMENT_ROOT']."/vendor/autoload.php";

// Permission check without page_guard.php to avoid header output
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Suppress any warnings/notices that might corrupt PDF output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

try {
    $reportType = $_GET['report'] ?? 'branch_outstanding';
    /* Reporting currency: explicit ?currency= filter, else the system default. */
    $reportCurrency = CurrencyService::normalize($_GET['currency'] ?? null, $pdo);
    $curSym = CurrencyService::symbol($reportCurrency, $pdo);
    // Only restrict/relabel data when the caller explicitly asked for a
    // currency; otherwise every currency's rows are kept and each row must
    // show its own real currency code instead of being mislabeled with the
    // default symbol.
    $explicitCurrency = (isset($_GET['currency']) && trim((string)$_GET['currency']) !== '')
        ? CurrencyService::normalize($_GET['currency'], $pdo)
        : null;
    $date = date('d M Y');
    $time = date('g:i A');
    $procurementVisibilityWhere = (function_exists('isProcurementVisibilityRestrictedRole')
        && function_exists('getProcurementVisibilitySqlCondition')
        && isProcurementVisibilityRestrictedRole())
        ? ' WHERE ' . getProcurementVisibilitySqlCondition('procurement_requests')
        : '';
    $procurementVisibilityJoinClause = (function_exists('isProcurementVisibilityRestrictedRole')
        && function_exists('getProcurementVisibilitySqlCondition')
        && isProcurementVisibilityRestrictedRole())
        ? ' AND ' . getProcurementVisibilitySqlCondition('pr')
        : '';

    // Initialize variables
    $title = "Report";
    $subtitle = "Report";
    $html = "";

    // Handle different report types
    switch ($reportType) {
        case 'procurement_status':
            $reqCurSql = "SELECT status, COUNT(*) as count, COALESCE(SUM(estimated_value), 0) as total_value FROM procurement_requests{$procurementVisibilityWhere}";
            $reqCurParams = [];
            if ($explicitCurrency !== null) {
                $reqCurSql .= $procurementVisibilityWhere ? ' AND currency = ?' : ' WHERE currency = ?';
                $reqCurParams[] = $explicitCurrency;
            }
            $reqCurSql .= " GROUP BY status ORDER BY count DESC";
            $reqCurStmt = $pdo->prepare($reqCurSql);
            $reqCurStmt->execute($reqCurParams);
            $data = $reqCurStmt->fetchAll();
            $title = "Procurement by Status";
            $subtitle = "Distribution of procurement requests across different statuses";
            
            $rows = '';
            foreach ($data as $i => $r) {
                $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
                $num = $i + 1;
                $status = htmlspecialchars(ucfirst(strtolower(str_replace('_', ' ', $r['status'] ?? 'Unknown'))));
                $count = intval($r['count']);
                $value = $curSym . number_format(floatval($r['total_value']), 2);
                $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$status}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
            }
            
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Status</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Count</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Value</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'procurement_type':
        $typeSql = "SELECT COALESCE(procurement_method, 'UNSPECIFIED') as type, COUNT(*) as count, COALESCE(SUM(estimated_value), 0) as total_value FROM procurement_requests{$procurementVisibilityWhere}";
        $typeParams = [];
        if ($explicitCurrency !== null) {
            $typeSql .= $procurementVisibilityWhere ? ' AND currency = ?' : ' WHERE currency = ?';
            $typeParams[] = $explicitCurrency;
        }
        $typeSql .= " GROUP BY procurement_method ORDER BY total_value DESC";
        $typeStmt = $pdo->prepare($typeSql);
        $typeStmt->execute($typeParams);
        $data = $typeStmt->fetchAll();
        $title = "Procurement by Type";
        $subtitle = "Procurement methods and their distribution";
        
        $typeLabels = [
            'SINGLE_SOURCE' => 'Single Source',
            'RESTRICTED_BIDDING' => 'Restricted Bidding',
            'NATIONAL_COMPETITIVE' => 'National Competitive',
            'INTERNATIONAL_COMPETITIVE' => 'International Competitive',
            'UNSPECIFIED' => 'Unspecified'
        ];
        
        $rows = '';
        foreach ($data as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $num = $i + 1;
            $type = htmlspecialchars($typeLabels[$r['type'] ?? 'UNSPECIFIED'] ?? $r['type']);
            $count = intval($r['count']);
            $value = $curSym . number_format(floatval($r['total_value']), 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$type}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Procurement Method</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Count</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Value</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'procurement_branch':
        $branchExtraJoin = $procurementVisibilityJoinClause;
        $branchParams = [];
        if ($explicitCurrency !== null) {
            $branchExtraJoin .= ' AND pr.currency = ?';
            $branchParams[] = $explicitCurrency;
        }
        $branchStmt = $pdo->prepare("SELECT b.branch_id, b.branch_name, COUNT(pr.request_id) as count, COALESCE(SUM(pr.estimated_value), 0) as total_value FROM branches b LEFT JOIN procurement_requests pr ON b.branch_id = pr.branch_id{$branchExtraJoin} GROUP BY b.branch_id, b.branch_name ORDER BY total_value DESC");
        $branchStmt->execute($branchParams);
        $data = $branchStmt->fetchAll();
        $title = "Procurement by Department/Branch";
        $subtitle = "Procurement requests and spending by department or branch";
        
        $rows = '';
        foreach ($data as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $num = $i + 1;
            $branch = htmlspecialchars($r['branch_name'] ?? 'Unknown');
            $count = intval($r['count']);
            $value = $curSym . number_format(floatval($r['total_value']), 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$branch}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Branch</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Requests</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Value</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'procurement_supplier':
        $data = $pdo->query("SELECT vendor_id, vendor_name, status FROM vendors ORDER BY vendor_name")->fetchAll();
        $title = "Procurement by Supplier";
        $subtitle = "Supplier performance and order summary";
        
        $rows = '';
        foreach ($data as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $num = $i + 1;
            $vendor = htmlspecialchars($r['vendor_name'] ?? 'Unknown');
            $status = htmlspecialchars($r['status'] ?? 'Active');
            $statusColor = ($status === 'Active') ? '#198754' : '#dc3545';
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$vendor}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;\"><span style=\"background:{$statusColor};color:#fff;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;\">{$status}</span></td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Supplier/Vendor</th><th style="padding:10px;color:#fff;text-align:center;font-size:11px;">Status</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'po_status':
        $poStatusSql = "SELECT status, COUNT(*) as count, COALESCE(SUM(po_total), 0) as total_amount FROM purchase_orders";
        $poStatusParams = [];
        if ($explicitCurrency !== null) {
            $poStatusSql .= " WHERE currency = ?";
            $poStatusParams[] = $explicitCurrency;
        }
        $poStatusSql .= " GROUP BY status ORDER BY count DESC";
        $poStatusStmt = $pdo->prepare($poStatusSql);
        $poStatusStmt->execute($poStatusParams);
        $statusData = $poStatusStmt->fetchAll();
        $title = "Purchase Order (PO) Status Report";
        $subtitle = "Complete overview of all purchase orders and their current status";
        
        $rows = '';
        foreach ($statusData as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $num = $i + 1;
            $status = htmlspecialchars($r['status'] ?? 'Unknown');
            $count = intval($r['count']);
            $value = $curSym . number_format(floatval($r['total_amount']), 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$status}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Status</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Count</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Amount</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'period':
    case 'periods':
        $periodSql = "SELECT DATE_FORMAT(po_date, '%Y-%m') as period, COUNT(*) as count, COALESCE(SUM(po_total), 0) as total FROM purchase_orders WHERE po_date IS NOT NULL";
        $periodParams = [];
        if ($explicitCurrency !== null) {
            $periodSql .= " AND currency = ?";
            $periodParams[] = $explicitCurrency;
        }
        $periodSql .= " GROUP BY DATE_FORMAT(po_date, '%Y-%m') ORDER BY period DESC LIMIT 12";
        $periodStmt = $pdo->prepare($periodSql);
        $periodStmt->execute($periodParams);
        $poData = $periodStmt->fetchAll();
        $title = "Period Reports - Purchase Orders";
        $subtitle = "Monthly procurement and purchase order activity";
        
        $rows = '';
        foreach ($poData as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $period = htmlspecialchars($r['period'] ?? 'Unknown');
            $count = intval($r['count']);
            $value = $curSym . number_format(floatval($r['total']), 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$period}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Period</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Count</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Amount</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'amounts_paid':
        $paidSql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') as period, COUNT(*) as count, COALESCE(SUM(p.payment_amount), 0) as total FROM payments p WHERE p.payment_date IS NOT NULL";
        $paidParams = [];
        if ($explicitCurrency !== null) {
            $paidSql .= " AND p.currency = ?";
            $paidParams[] = $explicitCurrency;
        }
        $paidSql .= " GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m') ORDER BY period DESC LIMIT 12";
        $paidStmt = $pdo->prepare($paidSql);
        $paidStmt->execute($paidParams);
        $paymentData = $paidStmt->fetchAll();
        $title = "Amounts Paid Report";
        $subtitle = "Payment tracking and analysis by period";
        
        $rows = '';
        foreach ($paymentData as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $period = htmlspecialchars($r['period'] ?? 'Unknown');
            $count = intval($r['count']);
            $value = $curSym . number_format(floatval($r['total']), 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$period}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$count}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$value}</td></tr>";
        }
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Period</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Payments</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Paid</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'outstanding':
        $outSql = "SELECT c.commitment_id, c.commitment_date, c.estimated_value, COALESCE(c.balance, c.estimated_value) as outstanding, pr.request_number FROM commitments c LEFT JOIN procurement_requests pr ON c.request_id = pr.request_id WHERE COALESCE(c.balance, c.estimated_value) > 0";
        $outParams = [];
        if ($explicitCurrency !== null) {
            $outSql .= " AND c.currency = ?";
            $outParams[] = $explicitCurrency;
        }
        $outSql .= " ORDER BY c.commitment_date DESC LIMIT 50";
        $outStmt = $pdo->prepare($outSql);
        $outStmt->execute($outParams);
        $data = $outStmt->fetchAll();
        $title = "Outstanding Commitments & PO";
        $subtitle = "Summary of open commitments and outstanding purchase orders";
        
        $rows = '';
        $totalOutstanding = 0;
        foreach ($data as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $num = $i + 1;
            $date = htmlspecialchars($r['commitment_date'] ?? 'N/A');
            $reqNum = htmlspecialchars($r['request_number'] ?? 'N/A');
            $commitment = $curSym . number_format(floatval($r['estimated_value'] ?? 0), 2);
            $outstanding = floatval($r['outstanding'] ?? 0);
            $outstandingStr = $curSym . number_format($outstanding, 2);
            $totalOutstanding += $outstanding;
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;\">{$date}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;\">{$reqNum}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$commitment}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;color:#dc3545;font-weight:700;\">{$outstandingStr}</td></tr>";
        }
        
        $fmtTotal = $curSym . number_format($totalOutstanding, 2);
        
        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:'Helvetica','Arial',sans-serif;color:#212529;font-size:12px;margin:0;padding:0;}</style></head><body>
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;"><table width="100%"><tr><td><span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Government Chemist - PIAMS</span><br><span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span></td><td style="text-align:right;"><span style="font-size:11px;">Generated: {$date} at {$time}</span></td></tr></table></div>
<div style="padding:20px 24px 10px;"><h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">{$title}</h2><p style="margin:0;color:#6c757d;font-size:11px;">{$subtitle}</p></div>
<div style="padding:0 24px 16px;"><div style="background:#fce4ec;border-radius:8px;padding:12px 14px;display:inline-block;\"><span style="font-size:10px;text-transform:uppercase;color:#6c757d;font-weight:600;\">Total Outstanding</span><br><span style="font-size:18px;font-weight:700;color:#dc3545;\">{$fmtTotal}</span></div></div>
<div style="padding:0 24px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;"><thead><tr style="background:#0b5e2b;"><th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Date</th><th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Request #</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Commitment</th><th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Outstanding</th></tr></thead><tbody>{$rows}</tbody></table></div>
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">&copy; {$date} Government Chemist &middot; Confidential &middot; PIAMS</div>
</body></html>
HTML;
        break;

    case 'branch_outstanding':
    default:
        /* The underlying invoices/payments carry their own currency; filter
           to the explicitly requested currency when given, otherwise keep
           every currency and label each row with its real code so mixed
           currencies are never summed together or mislabeled. */
        $boSql = "
            SELECT
                b.branch_name,
                i.currency AS currency,
                COALESCE(SUM(i.invoice_amount), 0) AS total_invoiced,
                COALESCE(SUM(pay.paid_amount), 0) AS total_paid,
                COALESCE(SUM(i.invoice_amount), 0) - COALESCE(SUM(pay.paid_amount), 0) AS outstanding
            FROM branches b
            JOIN procurement_requests pr ON pr.branch_id = b.branch_id
            JOIN commitments c ON c.request_id = pr.request_id
            JOIN purchase_orders po ON po.commitment_id = c.commitment_id
            JOIN invoices i ON i.po_id = po.po_id
            LEFT JOIN (
                SELECT invoice_id, SUM(payment_amount) AS paid_amount
                FROM payments
                GROUP BY invoice_id
            ) pay ON pay.invoice_id = i.invoice_id
        ";
        $boParams = [];
        if ($explicitCurrency !== null) {
            $boSql .= " WHERE i.currency = ?";
            $boParams[] = $explicitCurrency;
        }
        $boSql .= " GROUP BY b.branch_name, i.currency ORDER BY outstanding DESC, b.branch_name ASC";
        $boStmt = $pdo->prepare($boSql);
        $boStmt->execute($boParams);
        $data = $boStmt->fetchAll(PDO::FETCH_ASSOC);
        $title = "Branch Outstanding Report";
        $subtitle = "Financial summary of invoiced, paid & outstanding amounts per branch";

        // Grand totals are only meaningful within a single currency; group
        // them by currency rather than collapsing every currency into one sum.
        $grandByCurrency = [];
        foreach ($data as $r) {
            $cur = $r['currency'] ?? CurrencyService::defaultCurrency($pdo);
            if (!isset($grandByCurrency[$cur])) {
                $grandByCurrency[$cur] = ['invoiced' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
            }
            $grandByCurrency[$cur]['invoiced'] += (float) ($r['total_invoiced'] ?? 0);
            $grandByCurrency[$cur]['paid'] += (float) ($r['total_paid'] ?? 0);
            $grandByCurrency[$cur]['outstanding'] += (float) ($r['outstanding'] ?? 0);
        }

        $summaryRows = '';
        foreach ($grandByCurrency as $cur => $g) {
            $sym = CurrencyService::symbol($cur, $pdo);
            $summaryRows .= "<tr><td style=\"padding:4px 0;font-weight:600;\">{$cur}</td>"
                . "<td style=\"padding:4px 10px;text-align:right;\">{$sym}" . number_format($g['invoiced'], 2) . "</td>"
                . "<td style=\"padding:4px 10px;text-align:right;color:#198754;\">{$sym}" . number_format($g['paid'], 2) . "</td>"
                . "<td style=\"padding:4px 10px;text-align:right;color:#dc3545;font-weight:700;\">{$sym}" . number_format($g['outstanding'], 2) . "</td></tr>";
        }
        if ($summaryRows === '') {
            $sym = $curSym;
            $summaryRows = "<tr><td style=\"padding:4px 0;font-weight:600;\">" . htmlspecialchars($reportCurrency) . "</td>"
                . "<td style=\"padding:4px 10px;text-align:right;\">{$sym}0.00</td>"
                . "<td style=\"padding:4px 10px;text-align:right;color:#198754;\">{$sym}0.00</td>"
                . "<td style=\"padding:4px 10px;text-align:right;color:#dc3545;font-weight:700;\">{$sym}0.00</td></tr>";
        }

        $rows = '';
        foreach ($data as $i => $r) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8f9fa';
            $rowCur = $r['currency'] ?? CurrencyService::defaultCurrency($pdo);
            $rowSym = CurrencyService::symbol($rowCur, $pdo);
            $outVal = floatval($r['outstanding'] ?? 0);
            $outColor = $outVal > 0 ? '#dc3545' : '#198754';
            $num = $i + 1;
            $name = htmlspecialchars($r['branch_name'] ?? 'Unknown');
            $cur = htmlspecialchars($rowCur);
            $inv = $rowSym . number_format(floatval($r['total_invoiced'] ?? 0), 2);
            $pd  = $rowSym . number_format(floatval($r['total_paid'] ?? 0), 2);
            $out = $rowSym . number_format($outVal, 2);
            $rows .= "<tr style=\"background:{$bg};\"><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;color:#6c757d;\">{$num}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;font-weight:600;\">{$name}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:center;\">{$cur}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;\">{$inv}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;color:#198754;\">{$pd}</td><td style=\"padding:8px 10px;border-bottom:1px solid #e9ecef;text-align:right;font-weight:700;color:{$outColor};\">{$out}</td></tr>";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    color: #212529;
    font-size: 12px;
    margin: 0;
    padding: 0;
  }
</style>
</head>
<body>

<!-- Header Bar -->
<div style="background:linear-gradient(90deg, #0b5e2b, #c9a227);padding:16px 24px;color:#fff;">
  <table width="100%">
    <tr>
      <td>
        <span style="font-size:18px;font-weight:700;letter-spacing:0.5px;">Department of the Government Chemist</span><br>
        <span style="font-size:11px;opacity:0.85;">Procurement & Inventory Assets Management System</span>
      </td>
      <td style="text-align:right;">
        <span style="font-size:11px;">Generated: {$date} at {$time}</span>
      </td>
    </tr>
  </table>
</div>

<!-- Report Title -->
<div style="padding:20px 24px 10px;">
  <h2 style="margin:0 0 4px;font-size:20px;color:#1a1a2e;">Branch Outstanding Report</h2>
  <p style="margin:0;color:#6c757d;font-size:11px;">Financial summary of invoiced, paid &amp; outstanding amounts per branch</p>
</div>

<!-- Summary Totals (grouped per currency; never summed across currencies) -->
<div style="padding:0 24px 16px;">
  <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
    <thead>
      <tr style="background:#f1f3f5;">
        <th style="padding:4px 0;text-align:left;font-size:10px;text-transform:uppercase;color:#6c757d;">Currency</th>
        <th style="padding:4px 10px;text-align:right;font-size:10px;text-transform:uppercase;color:#6c757d;">Total Invoiced</th>
        <th style="padding:4px 10px;text-align:right;font-size:10px;text-transform:uppercase;color:#6c757d;">Total Paid</th>
        <th style="padding:4px 10px;text-align:right;font-size:10px;text-transform:uppercase;color:#6c757d;">Outstanding</th>
      </tr>
    </thead>
    <tbody>
      {$summaryRows}
    </tbody>
  </table>
</div>

<!-- Data Table -->
<div style="padding:0 24px;">
  <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
    <thead>
      <tr style="background:#0b5e2b;">
        <th style="padding:10px;color:#fff;text-align:center;font-size:11px;width:5%;">#</th>
        <th style="padding:10px;color:#fff;text-align:left;font-size:11px;">Branch</th>
        <th style="padding:10px;color:#fff;text-align:center;font-size:11px;">Currency</th>
        <th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Invoiced</th>
        <th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Total Paid</th>
        <th style="padding:10px;color:#fff;text-align:right;font-size:11px;">Outstanding</th>
      </tr>
    </thead>
    <tbody>
      {$rows}
    </tbody>
  </table>
</div>

<!-- Footer -->
<div style="padding:20px 24px 12px;text-align:center;color:#adb5bd;font-size:10px;border-top:1px solid #e9ecef;margin-top:24px;">
  &copy; {$date} Department of the Government Chemist &middot; Confidential &middot; PIAMS
</div>

</body>
</html>
HTML;
}


    // Generate PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');

    $pdf = new Dompdf($options);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4', in_array($reportType, ['branch_outstanding']) ? 'landscape' : 'portrait');
    $pdf->render();

    // Determine filename based on report type
    $filenames = [
        'procurement_status' => 'Procurement_by_Status',
        'procurement_type' => 'Procurement_by_Type',
        'procurement_branch' => 'Procurement_by_Branch',
        'procurement_supplier' => 'Procurement_by_Supplier',
        'po_status' => 'PO_Status_Report',
        'period' => 'Period_Reports',
        'periods' => 'Period_Reports',
        'amounts_paid' => 'Amounts_Paid_Report',
        'outstanding' => 'Outstanding_Commitments_PO',
        'branch_outstanding' => 'Branch_Outstanding'
    ];

    $filename = ($filenames[$reportType] ?? 'Report') . '.pdf';
    $pdf->stream($filename);
} catch (Exception $e) {
    http_response_code(500);
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
}
?>
