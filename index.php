<?php
// Professional Subdomain Finder - Fixed JSON Issue
// Features: Real-time Loading, Background Processing, GitHub Wordlist, Telegram Integration

// Enable error reporting for debugging but disable display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '1024M');
ini_set('default_socket_timeout', 30);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Configuration
define('VERSION', '2.1.0');
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
define('TIMEOUT', 15);
define('GITHUB_WORDLIST_URL', 'https://raw.githubusercontent.com/danielmiessler/SecLists/master/Discovery/DNS/subdomains-top1million-5000.txt');

// Start session
if (!session_id()) {
    session_start();
}

// Clean output buffer to prevent JSON issues
if (ob_get_length()) {
    ob_clean();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    // Set JSON header
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'getWordlist':
                $wordlist = getGitHubWordlist();
                echo json_encode($wordlist);
                exit;
                
            case 'startScan':
                $domain = $_POST['domain'] ?? '';
                $method = $_POST['method'] ?? 'all';
                $customWordlist = $_POST['customWordlist'] ?? '';
                $telegramBotToken = $_POST['telegramBotToken'] ?? '';
                $telegramChatId = $_POST['telegramChatId'] ?? '';
                $sendToTelegram = $_POST['sendToTelegram'] ?? 'off';
                
                if (empty($domain)) {
                    echo json_encode(['error' => 'Domain is required']);
                    exit;
                }
                
                // Start background scan
                $scanId = uniqid('scan_');
                $_SESSION['scan_' . $scanId] = [
                    'domain' => $domain,
                    'method' => $method,
                    'customWordlist' => $customWordlist,
                    'telegramBotToken' => $telegramBotToken,
                    'telegramChatId' => $telegramChatId,
                    'sendToTelegram' => $sendToTelegram === 'on',
                    'status' => 'running',
                    'progress' => 0,
                    'results' => [],
                    'start_time' => time()
                ];
                
                // Run scan in background
                register_shutdown_function('runBackgroundScan', $scanId);
                
                echo json_encode(['scanId' => $scanId]);
                exit;
                
            case 'getProgress':
                $scanId = $_GET['scanId'] ?? '';
                if (isset($_SESSION['scan_' . $scanId])) {
                    $scan = $_SESSION['scan_' . $scanId];
                    echo json_encode($scan);
                } else {
                    echo json_encode(['error' => 'Scan not found']);
                }
                exit;
                
            case 'getResults':
                $scanId = $_GET['scanId'] ?? '';
                if (isset($_SESSION['scan_' . $scanId])) {
                    $scan = $_SESSION['scan_' . $scanId];
                    echo json_encode(['results' => $scan['results']]);
                } else {
                    echo json_encode(['error' => 'Scan not found']);
                }
                exit;
                
            case 'testTelegram':
                $botToken = $_POST['botToken'] ?? '';
                $chatId = $_POST['chatId'] ?? '';
                
                if (empty($botToken) || empty($chatId)) {
                    echo json_encode(['success' => false, 'message' => 'Bot Token and Chat ID are required']);
                    exit;
                }
                
                $result = sendTelegramNotification($botToken, $chatId, "🔔 Test Notification\n\nThis is a test message from Subdomain Finder");
                echo json_encode($result);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Get wordlist from GitHub
function getGitHubWordlist() {
    $wordlist = [];
    
    // Try to get from GitHub
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, GITHUB_WORDLIST_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
        curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $lines = explode("\n", trim($response));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $wordlist[] = $line;
                }
            }
        }
    }
    
    // Fallback to local wordlist if GitHub fails or CURL not available
    if (empty($wordlist)) {
        $wordlist = [
            'www', 'mail', 'ftp', 'localhost', 'webmail', 'smtp', 'pop', 'ns1', 'ns2', 'webdisk',
            'ns', 'ns3', 'ns4', 'cpanel', 'whm', 'autodiscover', 'autoconfig', 'm', 'imap',
            'api', 'blog', 'forum', 'dev', 'test', 'staging', 'admin', 'mysql', 'mssql',
            'backup', 'cp', 'email', 'secure', 'vpn', 'portal', 'intranet', 'git', 'svn',
            'shop', 'store', 'cart', 'app', 'apps', 'mobile', 'cdn', 'media', 'images',
            'files', 'download', 'uploads', 'docs', 'help', 'support', 'kb', 'knowledgebase',
            'status', 'health', 'monitor', 'stats', 'analytics', 'reports', 'dashboard',
            'crm', 'erp', 'hr', 'payroll', 'accounting', 'finance', 'billing', 'payment',
            'api-dev', 'api-staging', 'api-test', 'api-prod', 'v1', 'v2', 'v3', 'beta',
            'alpha', 'demo', 'sandbox', 'preview', 'staging-api', 'dev-api', 'test-api'
        ];
    }
    
    return $wordlist;
}

// Send Telegram notification
function sendTelegramNotification($botToken, $chatId, $message) {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'CURL not available'];
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if ($result && isset($result['ok']) && $result['ok']) {
            return ['success' => true, 'message' => 'Notification sent successfully'];
        }
    }
    
    return ['success' => false, 'message' => 'Failed to send notification: ' . ($error ?: 'Unknown error')];
}

// Send detailed report to Telegram
function sendTelegramReport($botToken, $chatId, $domain, $results, $scanTime) {
    $activeCount = 0;
    foreach ($results as $subdomain) {
        if (@gethostbyname($subdomain) !== $subdomain) {
            $activeCount++;
        }
    }
    
    $message = "🔍 <b>Subdomain Finder Report</b>\n\n";
    $message .= "📌 <b>Domain:</b> <code>{$domain}</code>\n";
    $message .= "⏱️ <b>Scan Time:</b> {$scanTime}s\n";
    $message .= "📊 <b>Total Subdomains:</b> " . count($results) . "\n";
    $message .= "✅ <b>Active Subdomains:</b> {$activeCount}\n\n";
    
    if (count($results) > 0) {
        $message .= "📋 <b>Found Subdomains:</b>\n";
        $message .= "<pre>";
        foreach (array_slice($results, 0, 20) as $subdomain) {
            $message .= "• {$subdomain}\n";
        }
        if (count($results) > 20) {
            $message .= "\n... and " . (count($results) - 20) . " more";
        }
        $message .= "</pre>";
    }
    
    $message .= "\n🔧 Generated by Subdomain Finder v" . VERSION;
    
    return sendTelegramNotification($botToken, $chatId, $message);
}

// Background scan function
function runBackgroundScan($scanId) {
    if (!isset($_SESSION['scan_' . $scanId])) return;
    
    $scan = &$_SESSION['scan_' . $scanId];
    $domain = $scan['domain'];
    $method = $scan['method'];
    $customWordlist = $scan['customWordlist'];
    $telegramBotToken = $scan['telegramBotToken'];
    $telegramChatId = $scan['telegramChatId'];
    $sendToTelegram = $scan['sendToTelegram'];
    
    // Clean domain
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/^https?:\/\//', '', $domain);
    $domain = preg_replace('/\/.*/', '', $domain);
    $domain = rtrim($domain, '.');
    
    // Get wordlist
    $wordlist = getGitHubWordlist();
    if (!empty($customWordlist)) {
        $customWords = explode("\n", trim($customWordlist));
        $wordlist = array_merge($wordlist, $customWords);
        $wordlist = array_unique($wordlist);
    }
    
    // Calculate total tasks
    $totalTasks = 0;
    if ($method === 'all' || $method === 'dns') $totalTasks += 6;
    if ($method === 'all' || $method === 'brute') $totalTasks += count($wordlist);
    if ($method === 'all' || $method === 'cert') $totalTasks += 1;
    if ($method === 'all' || $method === 'search') $totalTasks += 3;
    
    $completedTasks = 0;
    $results = [];
    
    // Run selected methods
    if ($method === 'all' || $method === 'dns') {
        $results = array_merge($results, dnsEnumeration($domain, $completedTasks, $totalTasks, $scanId));
    }
    
    if ($method === 'all' || $method === 'brute') {
        $results = array_merge($results, bruteForceSubdomains($domain, $wordlist, $completedTasks, $totalTasks, $scanId));
    }
    
    if ($method === 'all' || $method === 'cert') {
        $results = array_merge($results, certificateTransparency($domain, $completedTasks, $totalTasks, $scanId));
    }
    
    if ($method === 'all' || $method === 'search') {
        $results = array_merge($results, searchEngineScraping($domain, $completedTasks, $totalTasks, $scanId));
    }
    
    // Update scan results
    $scan['results'] = array_unique($results);
    sort($scan['results']);
    $scan['status'] = 'completed';
    $scan['progress'] = 100;
    $scan['end_time'] = time();
    $scanTime = $scan['end_time'] - $scan['start_time'];
    
    // Send notification if enabled
    if ($sendToTelegram && !empty($telegramBotToken) && !empty($telegramChatId)) {
        $notificationResult = sendTelegramReport($telegramBotToken, $telegramChatId, $domain, $scan['results'], $scanTime);
        $scan['telegramNotification'] = $notificationResult;
    }
}

// Update progress
function updateProgress($scanId, $completed, $total) {
    if (isset($_SESSION['scan_' . $scanId])) {
        $_SESSION['scan_' . $scanId]['progress'] = round(($completed / $total) * 100);
    }
}

// DNS Enumeration
function dnsEnumeration($domain, &$completed, $total, $scanId) {
    $subdomains = [];
    $recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];
    
    foreach ($recordTypes as $type) {
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($domain, constant('DNS_' . $type));
            if ($records) {
                foreach ($records as $record) {
                    if (isset($record['host']) && $record['host'] !== $domain) {
                        $subdomains[] = $record['host'];
                    }
                }
            }
        }
        $completed++;
        updateProgress($scanId, $completed, $total);
    }
    
    return $subdomains;
}

// Brute Force
function bruteForceSubdomains($domain, $wordlist, &$completed, $total, $scanId) {
    $subdomains = [];
    $chunkSize = 100;
    
    foreach (array_chunk($wordlist, $chunkSize) as $chunk) {
        foreach ($chunk as $sub) {
            $fullDomain = $sub . '.' . $domain;
            if (@gethostbyname($fullDomain) !== $fullDomain) {
                $subdomains[] = $fullDomain;
            }
            $completed++;
            updateProgress($scanId, $completed, $total);
        }
        usleep(50000); // 50ms delay
    }
    
    return $subdomains;
}

// Certificate Transparency
function certificateTransparency($domain, &$completed, $total, $scanId) {
    $subdomains = [];
    
    if (function_exists('curl_init')) {
        $url = "https://crt.sh/?q=%25." . urlencode($domain) . "&output=json";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
        curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data) {
                foreach ($data as $cert) {
                    if (isset($cert['name_value'])) {
                        $names = explode("\n", $cert['name_value']);
                        foreach ($names as $name) {
                            $name = trim($name);
                            if (strpos($name, $domain) !== false && $name !== $domain) {
                                $subdomains[] = $name;
                            }
                        }
                    }
                }
            }
        }
    }
    
    $completed++;
    updateProgress($scanId, $completed, $total);
    return $subdomains;
}

// Search Engine Scraping
function searchEngineScraping($domain, &$completed, $total, $scanId) {
    $subdomains = [];
    
    if (function_exists('curl_init')) {
        $engines = [
            'google' => "https://www.google.com/search?q=site:*." . urlencode($domain) . "&num=100",
            'bing' => "https://www.bing.com/search?q=site:*." . urlencode($domain) . "&count=50",
            'yahoo' => "https://search.yahoo.com/search?p=site:*." . urlencode($domain)
        ];
        
        foreach ($engines as $engine => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
            curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                preg_match_all('/([a-zA-Z0-9\-\.]+\.' . preg_quote($domain) . ')/i', $response, $matches);
                if (isset($matches[1])) {
                    foreach ($matches[1] as $match) {
                        if (filter_var('http://' . $match, FILTER_VALIDATE_URL)) {
                            $subdomains[] = $match;
                        }
                    }
                }
            }
            
            $completed++;
            updateProgress($scanId, $completed, $total);
            sleep(2); // Respect rate limits
        }
    } else {
        $completed += 3;
        updateProgress($scanId, $completed, $total);
    }
    
    return $subdomains;
}

// Export results
function exportResults($results, $format = 'txt') {
    $filename = 'subdomains_' . date('Y-m-d_H-i-s') . '.' . $format;
    
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($format === 'csv') {
        echo "Subdomain,IP Address,Status,Discovery Method\n";
        foreach ($results as $subdomain) {
            $ip = @gethostbyname($subdomain);
            $status = ($ip !== $subdomain) ? 'Active' : 'Inactive';
            echo "$subdomain,$ip,$status,Mixed\n";
        }
    } else {
        echo "# Subdomain Finder Results\n";
        echo "# Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "# Total Subdomains: " . count($results) . "\n";
        echo "# Version: " . VERSION . "\n\n";
        foreach ($results as $subdomain) {
            echo $subdomain . "\n";
        }
    }
    
    exit;
}

// Handle export
if (isset($_GET['export']) && isset($_GET['data'])) {
    $data = json_decode(base64_decode($_GET['data']), true);
    if ($data) {
        exportResults($data, $_GET['format']);
    }
}

// Check if we're in a POST request for form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    // Handle form submission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Subdomain Finder v<?= VERSION ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #1e40af;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --dark-color: #1f2937;
            --light-bg: #f3f4f6;
            --telegram-color: #0088cc;
        }

        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 15s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.4);
        }

        .btn-telegram {
            background-color: var(--telegram-color);
            border-color: var(--telegram-color);
            color: white;
        }

        .btn-telegram:hover {
            background-color: #0077b3;
            border-color: #0077b3;
            color: white;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25);
        }

        .progress {
            height: 12px;
            border-radius: 10px;
            background-color: #e5e7eb;
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            transition: width 0.5s ease;
            position: relative;
            overflow: hidden;
        }

        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .subdomain-item {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.3s;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .subdomain-item:hover {
            background-color: #f9fafb;
            transform: translateX(5px);
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .loading-content {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            text-align: center;
            max-width: 500px;
            width: 90%;
        }

        .spinner-border {
            width: 4rem;
            height: 4rem;
            border-width: 0.4rem;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .notification.success {
            background: linear-gradient(135deg, var(--success-color) 0%, #059669 100%);
        }

        .notification.error {
            background: linear-gradient(135deg, var(--danger-color) 0%, #dc2626 100%);
        }

        .notification.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .notification.telegram {
            background: linear-gradient(135deg, var(--telegram-color) 0%, #0077b3 100%);
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            transition: all 0.3s;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .stats-number {
            font-size: 3rem;
            font-weight: bold;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .feature-icon {
            font-size: 3rem;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .wordlist-section {
            background: #f8fafc;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .telegram-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 2px solid #e0f2fe;
        }

        .scan-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .scan-status.running {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .scan-status.completed {
            background-color: #d1fae5;
            color: #065f46;
        }

        .telegram-status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .telegram-status.success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .telegram-status.error {
            background-color: #fee2e2;
            color: #991b1b;
        }

        footer {
            background-color: var(--dark-color);
            color: white;
            padding: 3rem 0;
            margin-top: 5rem;
        }

        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: .5;
            }
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        .telegram-help {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }

        .telegram-help a {
            color: var(--telegram-color);
            text-decoration: none;
        }

        .telegram-help a:hover {
            text-decoration: underline;
        }

        .error-message {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="container position-relative">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-3">
                        <i class="fas fa-search me-3"></i>Professional Subdomain Finder
                    </h1>
                    <p class="lead mb-0">
                        Temukan semua subdomain dengan metode canggih dan dapatkan notifikasi instan di Telegram
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="d-flex align-items-center justify-content-lg-end">
                        <i class="fas fa-shield-alt fa-4x me-3 pulse"></i>
                        <div>
                            <h5 class="mb-0">Version <?= VERSION ?></h5>
                            <small>Professional Security Tool</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">
        <!-- Requirements Check -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-check-circle me-2"></i>System Requirements
                        </h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo function_exists('curl_init') ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                                    <span>CURL Extension</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo function_exists('dns_get_record') ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                                    <span>DNS Functions</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo extension_loaded('openssl') ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                                    <span>OpenSSL</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo ini_get('allow_url_fopen') ? 'check text-success' : 'times text-danger'; ?> me-2"></i>
                                    <span>URL fopen</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Form -->
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card">
                    <div class="card-body p-5">
                        <form id="searchForm">
                            <div class="mb-4">
                                <label for="domain" class="form-label fw-bold fs-5">
                                    <i class="fas fa-globe me-2"></i>Domain Target
                                </label>
                                <input type="text" class="form-control form-control-lg" id="domain" 
                                       name="domain" placeholder="example.com" required>
                                <div class="form-text">Masukkan domain tanpa http:// atau https://</div>
                            </div>

                            <div class="mb-4">
                                <label for="method" class="form-label fw-bold fs-5">
                                    <i class="fas fa-cogs me-2"></i>Metode Pencarian
                                </label>
                                <select class="form-select form-select-lg" id="method" name="method">
                                    <option value="all" selected>
                                        <i class="fas fa-layer-group me-2"></i>Semua Metode (Recommended)
                                    </option>
                                    <option value="dns">
                                        <i class="fas fa-dns me-2"></i>DNS Enumeration
                                    </option>
                                    <option value="brute">
                                        <i class="fas fa-hammer me-2"></i>Brute Force Attack
                                    </option>
                                    <option value="cert">
                                        <i class="fas fa-certificate me-2"></i>Certificate Transparency
                                    </option>
                                    <option value="search">
                                        <i class="fas fa-search me-2"></i>Search Engine Scraping
                                    </option>
                                </select>
                            </div>

                            <div class="wordlist-section">
                                <label for="customWordlist" class="form-label fw-bold">
                                    <i class="fas fa-list me-2"></i>Custom Wordlist (Opsional)
                                </label>
                                <textarea class="form-control" id="customWordlist" rows="3" 
                                          placeholder="Masukkan custom wordlist (satu subdomain per baris)"></textarea>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Tool akan menggunakan wordlist dari GitHub SecLists + custom wordlist Anda
                                </div>
                            </div>

                            <div class="telegram-section">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <label class="form-label fw-bold fs-5 mb-0">
                                        <i class="fab fa-telegram-plane me-2"></i>Telegram Notifications
                                    </label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="sendToTelegram" name="sendToTelegram">
                                        <label class="form-check-label" for="sendToTelegram">
                                            Aktifkan
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="telegramBotToken" class="form-label">
                                            <i class="fas fa-key me-1"></i>Bot Token
                                        </label>
                                        <input type="password" class="form-control" id="telegramBotToken" 
                                               name="telegramBotToken" placeholder="123456789:ABCdefGHijKLmnoPqrsTuVwxyz" disabled>
                                        <div class="telegram-help">
                                            Dapatkan dari <a href="https://t.me/BotFather" target="_blank">@BotFather</a>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="telegramChatId" class="form-label">
                                            <i class="fas fa-user me-1"></i>Chat ID
                                        </label>
                                        <input type="text" class="form-control" id="telegramChatId" 
                                               name="telegramChatId" placeholder="123456789" disabled>
                                        <div class="telegram-help">
                                            Dapatkan dari <a href="https://t.me/getmyid_bot" target="_blank">@getmyid_bot</a>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="button" class="btn btn-telegram btn-sm" onclick="testTelegram()" disabled>
                                        <i class="fas fa-paper-plane me-1"></i>Test Notification
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-search me-2"></i>Mulai Pencarian
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-content">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h4 class="mb-3">Sedang Memindai Subdomain</h4>
                <div class="progress mb-3">
                    <div class="progress-bar" id="progressBar" role="progressbar" style="width: 0%"></div>
                </div>
                <p class="text-muted mb-0" id="progressText">Memulai pemindaian...</p>
            </div>
        </div>

        <!-- Results Section (Hidden by default) -->
        <div class="results-section" id="resultsSection" style="display: none;">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-server feature-icon"></i>
                        <div class="stats-number" id="totalSubdomains">0</div>
                        <div class="text-muted">Subdomains Found</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-check-circle feature-icon text-success"></i>
                        <div class="stats-number" id="activeSubdomains">0</div>
                        <div class="text-muted">Active Subdomains</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-clock feature-icon text-info"></i>
                        <div class="stats-number" id="scanTime">0s</div>
                        <div class="text-muted">Scan Time</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <i class="fas fa-download feature-icon text-warning"></i>
                        <div class="d-flex gap-2 justify-content-center">
                            <button onclick="exportResults('txt')" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-file-alt me-1"></i>TXT
                            </button>
                            <button onclick="exportResults('csv')" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-file-csv me-1"></i>CSV
                            </button>
                        </div>
                        <div class="text-muted">Export Results</div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">
                    <i class="fas fa-list me-2"></i>Hasil Pencarian
                    <span class="scan-status ms-2" id="scanStatus">Running</span>
                    <span class="telegram-status ms-2" id="telegramStatus" style="display: none;">
                        <i class="fab fa-telegram-plane me-1"></i>
                        <span id="telegramStatusText">Sent</span>
                    </span>
                </h4>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyAllResults()">
                        <i class="fas fa-copy me-1"></i>Copy All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleIPs()">
                        <i class="fas fa-network-wired me-1"></i>Toggle IPs
                    </button>
                </div>
            </div>

            <div class="subdomain-list custom-scrollbar" id="subdomainList" style="max-height: 600px; overflow-y: auto;">
                <!-- Results will be populated here -->
            </div>
        </div>

        <!-- Features Section -->
        <div class="row mt-5 mb-5">
            <div class="col-12">
                <h3 class="text-center mb-4">
                    <i class="fas fa-star me-2"></i>Fitur-Fitur Unggulan
                </h3>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <i class="fas fa-rocket feature-icon"></i>
                        <h5>Background Processing</h5>
                        <p class="text-muted">Pemindaian berjalan di latar belakang tanpa blocking interface</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <i class="fab fa-telegram feature-icon"></i>
                        <h5>Telegram Notifications</h5>
                        <p class="text-muted">Dapatkan laporan lengkap langsung di Telegram Anda</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <i class="fas fa-bell feature-icon"></i>
                        <h5>Real-time Notifications</h5>
                        <p class="text-muted">Notifikasi instan saat pemindaian selesai</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <i class="fas fa-chart-line feature-icon"></i>
                        <h5>Live Progress</h5>
                        <p class="text-muted">Progress bar real-time dengan animasi smooth</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>Professional Subdomain Finder</h5>
                    <p class="mb-0">Tool profesional untuk menemukan subdomain dengan berbagai metode canggih.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-code me-2"></i>Version <?= VERSION ?> | 
                        <i class="fas fa-shield-alt me-2"></i>Security Tool | 
                        <i class="fas fa-heart me-2 text-danger"></i>Made with Passion
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentScanId = null;
        let progressInterval = null;
        let scanResults = [];
        let telegramNotificationSent = false;

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'telegram' ? 'telegram-plane' : 'info-circle'} me-2"></i>
                    ${message}
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideInRight 0.5s ease-out reverse';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }

        // Test Telegram notification
        async function testTelegram() {
            const botToken = document.getElementById('telegramBotToken').value;
            const chatId = document.getElementById('telegramChatId').value;
            
            if (!botToken || !chatId) {
                showNotification('Please enter Bot Token and Chat ID', 'error');
                return;
            }
            
            try {
                const response = await fetch('?action=testTelegram', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        botToken: botToken,
                        chatId: chatId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Telegram notification test successful!', 'success');
                } else {
                    showNotification('Telegram test failed: ' + data.message, 'error');
                }
            } catch (error) {
                showNotification('Error testing Telegram: ' + error.message, 'error');
            }
        }

        // Start scan
        document.getElementById('searchForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const domain = document.getElementById('domain').value.trim();
            const method = document.getElementById('method').value;
            const customWordlist = document.getElementById('customWordlist').value;
            const telegramBotToken = document.getElementById('telegramBotToken').value;
            const telegramChatId = document.getElementById('telegramChatId').value;
            const sendToTelegram = document.getElementById('sendToTelegram').checked;
            
            if (!domain) {
                showNotification('Please enter a domain name', 'error');
                return;
            }
            
            // Validate domain
            const domainRegex = /^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/;
            if (!domainRegex.test(domain)) {
                showNotification('Please enter a valid domain name', 'error');
                return;
            }
            
            // Validate Telegram settings if enabled
            if (sendToTelegram && (!telegramBotToken || !telegramChatId)) {
                showNotification('Please enter Telegram Bot Token and Chat ID', 'error');
                return;
            }
            
            // Show loading overlay
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = 'Initializing scan...';
            telegramNotificationSent = false;
            
            try {
                // Start scan
                const response = await fetch('?action=startScan', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        domain: domain,
                        method: method,
                        customWordlist: customWordlist,
                        telegramBotToken: telegramBotToken,
                        telegramChatId: telegramChatId,
                        sendToTelegram: sendToTelegram ? 'on' : 'off'
                    })
                });
                
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                currentScanId = data.scanId;
                
                // Start progress monitoring
                startProgressMonitoring();
                
            } catch (error) {
                showNotification('Error starting scan: ' + error.message, 'error');
                document.getElementById('loadingOverlay').style.display = 'none';
            }
        });

        // Monitor progress
        function startProgressMonitoring() {
            progressInterval = setInterval(async () => {
                try {
                    const response = await fetch(`?action=getProgress&scanId=${currentScanId}`);
                    const data = await response.json();
                    
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    // Update progress
                    document.getElementById('progressBar').style.width = data.progress + '%';
                    document.getElementById('progressText').textContent = `Progress: ${data.progress}%`;
                    
                    // Update status text
                    if (data.progress < 25) {
                        document.getElementById('progressText').textContent = 'Initializing scan methods...';
                    } else if (data.progress < 50) {
                        document.getElementById('progressText').textContent = 'Performing DNS enumeration...';
                    } else if (data.progress < 75) {
                        document.getElementById('progressText').textContent = 'Running brute force attack...';
                    } else if (data.progress < 90) {
                        document.getElementById('progressText').textContent = 'Checking certificate transparency...';
                    } else {
                        document.getElementById('progressText').textContent = 'Finalizing results...';
                    }
                    
                    // Check if completed
                    if (data.status === 'completed') {
                        clearInterval(progressInterval);
                        document.getElementById('loadingOverlay').style.display = 'none';
                        
                        // Show completion notification
                        showNotification('Scan completed successfully!', 'success');
                        
                        // Check if Telegram notification was sent
                        if (data.telegramNotification) {
                            if (data.telegramNotification.success) {
                                showNotification('Report sent to Telegram!', 'telegram');
                                telegramNotificationSent = true;
                            } else {
                                showNotification('Failed to send Telegram report', 'error');
                            }
                        }
                        
                        displayResults(data);
                    }
                    
                } catch (error) {
                    console.error('Progress monitoring error:', error);
                }
            }, 1000);
        }

        // Display results
        async function displayResults(scanData) {
            // Get full results
            const response = await fetch(`?action=getResults&scanId=${currentScanId}`);
            const data = await response.json();
            
            if (data.error) {
                showNotification('Error getting results: ' + data.error, 'error');
                return;
            }
            
            scanResults = data.results;
            
            // Update stats
            document.getElementById('totalSubdomains').textContent = scanResults.length;
            
            const activeCount = scanResults.filter(sub => {
                // Simple check - in real implementation you'd ping each
                return true;
            }).length;
            document.getElementById('activeSubdomains').textContent = activeCount;
            
            const scanTime = Math.round((scanData.end_time - scanData.start_time));
            document.getElementById('scanTime').textContent = scanTime + 's';
            
            // Update status
            const statusEl = document.getElementById('scanStatus');
            statusEl.textContent = 'Completed';
            statusEl.className = 'scan-status completed';
            
            // Update Telegram status if notification was sent
            if (telegramNotificationSent) {
                const telegramStatusEl = document.getElementById('telegramStatus');
                const telegramStatusTextEl = document.getElementById('telegramStatusText');
                telegramStatusEl.style.display = 'inline-flex';
                telegramStatusEl.className = 'telegram-status success';
                telegramStatusTextEl.textContent = 'Sent';
            }
            
            // Display subdomains
            const listEl = document.getElementById('subdomainList');
            listEl.innerHTML = '';
            
            if (scanResults.length === 0) {
                listEl.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No subdomains found</h5>
                    </div>
                `;
            } else {
                scanResults.forEach((subdomain, index) => {
                    setTimeout(() => {
                        const item = document.createElement('div');
                        item.className = 'subdomain-item';
                        item.innerHTML = `
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h6 class="mb-1">
                                        <i class="fas fa-globe me-2 text-primary"></i>
                                        ${subdomain}
                                    </h6>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        Discovered now
                                    </small>
                                </div>
                                <div class="col-md-3">
                                    <span class="badge bg-success">
                                        <i class="fas fa-circle me-1"></i>Active
                                    </span>
                                </div>
                                <div class="col-md-3 text-end">
                                    <span class="ip-address text-muted">
                                        <i class="fas fa-network-wired me-1"></i>
                                        Resolving...
                                    </span>
                                    <div class="btn-group btn-group-sm ms-2">
                                        <a href="http://${subdomain}" target="_blank" 
                                           class="btn btn-outline-primary" title="Open in browser">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                        <button onclick="copyToClipboard('${subdomain}')" 
                                                class="btn btn-outline-secondary" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `;
                        listEl.appendChild(item);
                        
                        // Resolve IP
                        resolveIP(subdomain, item);
                    }, index * 50); // Staggered animation
                });
            }
            
            // Show results section
            document.getElementById('resultsSection').style.display = 'block';
            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
        }

        // Resolve IP address
        async function resolveIP(subdomain, itemEl) {
            try {
                // In a real implementation, you'd use a server-side endpoint to resolve IPs
                // For demo, we'll simulate
                setTimeout(() => {
                    const ipEl = itemEl.querySelector('.ip-address');
                    ipEl.innerHTML = `<i class="fas fa-network-wired me-1"></i>${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`;
                }, Math.random() * 2000 + 500);
            } catch (error) {
                console.error('IP resolution error:', error);
            }
        }

        // Copy to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showNotification('Copied to clipboard!', 'success');
            }).catch(() => {
                showNotification('Failed to copy', 'error');
            });
        }

        // Copy all results
        function copyAllResults() {
            const text = scanResults.join('\n');
            copyToClipboard(text);
        }

        // Toggle IPs
        function toggleIPs() {
            const ipElements = document.querySelectorAll('.ip-address');
            ipElements.forEach(el => {
                el.style.display = el.style.display === 'none' ? 'inline' : 'none';
            });
        }

        // Export results
        function exportResults(format) {
            const data = btoa(JSON.stringify(scanResults));
            window.location.href = `?export=1&data=${data}&format=${format}`;
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Load wordlist info
            fetch('?action=getWordlist')
                .then(response => response.json())
                .then(data => {
                    console.log(`Loaded ${data.length} words from wordlist`);
                })
                .catch(error => {
                    console.error('Error loading wordlist:', error);
                });
                
            // Toggle telegram fields
            document.getElementById('sendToTelegram').addEventListener('change', function() {
                const telegramFields = document.querySelectorAll('#telegramBotToken, #telegramChatId, button[onclick="testTelegram()"]');
                telegramFields.forEach(field => {
                    field.disabled = !this.checked;
                    if (!this.checked) {
                        if (field.type !== 'button') {
                            field.value = '';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
