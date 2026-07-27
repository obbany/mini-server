<?php
/**
 * মিনি সার্ভার - পাবলিক ফাইল ম্যানেজার
 * GitHub: https://github.com/obbany/mini-server
 * Version: 2.0
 */

// ==================== কনফিগারেশন ====================
error_reporting(0);
ini_set('display_errors', 0);

$baseDir = __DIR__ . '/public_files';
$adminPassword = 'admin123'; // আপনার পছন্দমত পাসওয়ার্ড দিন
$siteName = 'মিনি সার্ভার - ফাইল ম্যানেজার';
$siteDescription = 'আপনার ফ্রেন্ড সার্কেলের জন্য পাবলিক ফাইল শেয়ারিং প্লাটফর্ম';

// ফোল্ডার তৈরি
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
    file_put_contents($baseDir . '/index.html', '<html><body><h1>403 Forbidden</h1></body></html>');
}

// সেশন স্টার্ট
session_start();

// বর্তমান ডিরেক্টরি নির্ধারণ
$currentDir = '';
if (isset($_GET['dir'])) {
    $requestedDir = str_replace(['..', './', '\\'], '', $_GET['dir']);
    $realBase = realpath($baseDir);
    $fullPath = $baseDir . '/' . trim($requestedDir, '/');
    $realPath = realpath($fullPath);
    if ($realPath && strpos($realPath, $realBase) === 0) {
        $currentDir = trim($requestedDir, '/');
    }
}
$targetDir = $baseDir . ($currentDir ? '/' . $currentDir : '');

// লগইন সিস্টেম
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (isset($_POST['password']) && $_POST['password'] === $adminPassword) {
    $_SESSION['logged_in'] = true;
    $isLoggedIn = true;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ==================== অ্যাকশন হ্যান্ডলিং ====================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $action = $_POST['action'] ?? '';
    
    // ফাইল আপলোড
    if ($action === 'upload' && !empty($_FILES['files']['name'][0])) {
        $uploaded = 0;
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;
            
            $fileName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_FILES['files']['name'][$key]);
            $targetPath = $targetDir . '/' . $fileName;
            
            $counter = 1;
            $originalName = pathinfo($fileName, PATHINFO_FILENAME);
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            while (file_exists($targetPath)) {
                $fileName = $originalName . '_' . $counter . '.' . $extension;
                $targetPath = $targetDir . '/' . $fileName;
                $counter++;
            }
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $uploaded++;
                
                // জিপ ফাইল অটো-এক্সট্র্যাক্ট
                if (strtolower($extension) === 'zip' && class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($targetPath) === true) {
                        $extractDir = $targetDir . '/' . $originalName;
                        if (!is_dir($extractDir)) mkdir($extractDir, 0755, true);
                        $zip->extractTo($extractDir);
                        $zip->close();
                        unlink($targetPath);
                    }
                }
            }
        }
        if ($uploaded > 0) {
            $message = "✅ {$uploaded}টি ফাইল সফলভাবে আপলোড হয়েছে!";
            $messageType = 'success';
        }
    }
    
    // ফাইল/ফোল্ডার ডিলিট
    if ($action === 'delete' && isset($_POST['file'])) {
        $file = $_POST['file'];
        $filePath = $targetDir . '/' . basename($file);
        if (file_exists($filePath)) {
            if (is_dir($filePath)) {
                rrmdir($filePath);
            } else {
                unlink($filePath);
            }
            $message = "✅ সফলভাবে ডিলিট করা হয়েছে!";
            $messageType = 'success';
        }
    }
    
    // রিনেম
    if ($action === 'rename' && isset($_POST['oldname']) && isset($_POST['newname'])) {
        $oldName = $_POST['oldname'];
        $newName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['newname']);
        $oldPath = $targetDir . '/' . $oldName;
        $newPath = $targetDir . '/' . $newName;
        
        if (file_exists($oldPath) && !file_exists($newPath) && rename($oldPath, $newPath)) {
            $message = "✅ '{$oldName}' → '{$newName}' রিনেম করা হয়েছে!";
            $messageType = 'success';
        } else {
            $message = "❌ রিনেম করা সম্ভব হয়নি!";
            $messageType = 'error';
        }
    }
    
    // নতুন ফাইল/ফোল্ডার তৈরি
    if ($action === 'create' && isset($_POST['name'])) {
        $name = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['name']);
        $type = $_POST['type'] ?? 'file';
        $newPath = $targetDir . '/' . $name;
        
        if (!file_exists($newPath)) {
            if ($type === 'folder') {
                mkdir($newPath, 0755, true);
            } else {
                file_put_contents($newPath, '');
            }
            $message = "✅ '{$name}' তৈরি করা হয়েছে!";
            $messageType = 'success';
        } else {
            $message = "❌ এই নামটি ইতিমধ্যে ব্যবহার করা হয়েছে!";
            $messageType = 'error';
        }
    }
    
    // ফাইল এডিট/সেভ
    if ($action === 'save' && isset($_POST['file']) && isset($_POST['content'])) {
        $filePath = $targetDir . '/' . basename($_POST['file']);
        if (file_exists($filePath) && file_put_contents($filePath, $_POST['content'])) {
            $message = "✅ ফাইল সফলভাবে সংরক্ষিত হয়েছে!";
            $messageType = 'success';
        }
    }
}

// ==================== ফাইল লিস্টিং ====================
$items = [];
if (is_dir($targetDir)) {
    $scanned = scandir($targetDir);
    foreach ($scanned as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = $targetDir . '/' . $item;
        $isDir = is_dir($itemPath);
        $items[] = [
            'name' => $item,
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : filesize($itemPath),
            'modified' => filemtime($itemPath),
            'type' => $isDir ? 'folder' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
            'permissions' => substr(sprintf('%o', fileperms($itemPath)), -4)
        ];
    }
    
    // সর্টিং: ফোল্ডার আগে, তারপর নাম অনুযায়ী
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] - $a['is_dir'];
        }
        return strcasecmp($a['name'], $b['name']);
    });
}

// AJAX রিকুয়েস্ট হ্যান্ডলিং
if (isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $filePath = $targetDir . '/' . basename($_GET['file']);
    if (file_exists($filePath) && is_file($filePath)) {
        echo json_encode([
            'success' => true,
            'content' => file_get_contents($filePath),
            'size' => filesize($filePath)
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'ফাইল পাওয়া যায়নি']);
    }
    exit;
}

// ==================== ইউটিলিটি ফাংশন ====================
function rrmdir($dir) {
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    return rmdir($dir);
}

function humanFileSize($bytes) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

function getFileIcon($type) {
    $icons = [
        'php' => 'fa-php',
        'html' => 'fa-html5',
        'css' => 'fa-css3-alt',
        'js' => 'fa-js-square',
        'jpg' => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image',
        'gif' => 'fa-file-image',
        'svg' => 'fa-file-image',
        'pdf' => 'fa-file-pdf',
        'zip' => 'fa-file-archive',
        'rar' => 'fa-file-archive',
        'tar' => 'fa-file-archive',
        'gz' => 'fa-file-archive',
        'mp4' => 'fa-file-video',
        'mp3' => 'fa-file-audio',
        'txt' => 'fa-file-alt',
        'md' => 'fa-file-alt',
        'json' => 'fa-file-code',
        'xml' => 'fa-file-code',
    ];
    return $icons[$type] ?? 'fa-file';
}

function getCurrentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}
?>
<!DOCTYPE html>
<html lang="bn" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteName ?></title>
    <meta name="description" content="<?= $siteDescription ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0e27;
            --bg-secondary: #131838;
            --bg-card: #1a1f4e;
            --text-primary: #e8eaed;
            --text-secondary: #9aa0a6;
            --accent-1: #8b5cf6;
            --accent-2: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --border: #2d3561;
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #1a1040 50%, var(--bg-primary) 100%);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* অ্যানিমেটেড ব্যাকগ্রাউন্ড */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(139, 92, 246, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(236, 72, 153, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 50% 80%, rgba(59, 130, 246, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        .app-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        /* গ্লাসমর্ফিজম কার্ড */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            border-color: var(--accent-1);
            box-shadow: 0 8px 32px rgba(139, 92, 246, 0.2);
        }

        /* হেডার */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: white;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .logo-text h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-text p {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        /* স্ট্যাটাস ব্যাজ */
        .status-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
        }

        .status-badge.online { 
            border-color: var(--success);
            color: var(--success);
        }

        .status-badge.files { 
            border-color: var(--info);
            color: var(--info);
        }

        /* টুলবার */
        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            color: var(--text-primary);
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            backdrop-filter: blur(10px);
        }

        .btn:hover {
            background: var(--accent-1);
            border-color: var(--accent-1);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(139, 92, 246, 0.3);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
            border: none;
            color: white;
        }

        .btn-success { 
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .btn-danger { 
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }

        /* ব্রেডক্রাম্ব */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 0;
            font-size: 14px;
        }

        .breadcrumb a {
            color: var(--accent-1);
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb .separator {
            color: var(--text-secondary);
        }

        /* ফাইল গ্রিড */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .file-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .file-card:hover {
            border-color: var(--accent-1);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .file-card-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
        }

        .file-icon {
            width: 50px;
            height: 50px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .file-icon.folder {
            color: var(--warning);
        }

        .file-icon.image {
            color: var(--success);
        }

        .file-icon.code {
            color: var(--info);
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-weight: 600;
            font-size: 16px;
            word-break: break-word;
            margin-bottom: 5px;
        }

        .file-name a {
            color: var(--text-primary);
            text-decoration: none;
        }

        .file-name a:hover {
            color: var(--accent-1);
        }

        .file-meta {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .file-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        /* মোডাল */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 30px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }

        .close-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s;
        }

        .close-btn:hover {
            background: var(--danger);
            border-color: var(--danger);
        }

        .input-group {
            margin-bottom: 15px;
        }

        .input-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            font-size: 14px;
        }

        .input-field {
            width: 100%;
            padding: 12px 16px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 14px;
            transition: all 0.3s;
        }

        .input-field:focus {
            outline: none;
            border-color: var(--accent-1);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        textarea.input-field {
            min-height: 300px;
            font-family: 'Courier New', monospace;
            resize: vertical;
        }

        /* টোস্ট নোটিফিকেশন */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            padding: 16px 24px;
            border-radius: 12px;
            background: var(--bg-secondary);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(20px);
            animation: slideInRight 0.3s ease;
            max-width: 400px;
        }

        @keyframes slideInRight {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.success { border-color: var(--success); }
        .toast.error { border-color: var(--danger); }

        /* আপলোড এরিয়া */
        .upload-dropzone {
            border: 2px dashed var(--glass-border);
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            margin: 20px 0;
        }

        .upload-dropzone:hover,
        .upload-dropzone.drag-over {
            border-color: var(--accent-1);
            background: rgba(139, 92, 246, 0.1);
        }

        .upload-dropzone i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--accent-1);
        }

        /* এম্পটি স্টেট */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 80px;
            color: var(--glass-border);
            margin-bottom: 20px;
        }

        /* রেস্পন্সিভ ডিজাইন */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .file-grid {
                grid-template-columns: 1fr;
            }

            .toolbar {
                justify-content: center;
            }

            .modal {
                width: 95%;
                padding: 20px;
                margin: 10px;
            }

            .logo-icon {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }

            .logo-text h1 {
                font-size: 22px;
            }
        }

        /* লোডিং স্পিনার */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--glass-border);
            border-top-color: var(--accent-1);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- লগইন স্ক্রিন -->
    <?php if (!$isLoggedIn): ?>
    <div style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px;">
        <div class="glass-card" style="max-width:400px; width:100%; text-align:center;">
            <div style="margin-bottom:30px;">
                <div class="logo-icon" style="margin:0 auto 20px;">
                    <i class="fas fa-lock"></i>
                </div>
                <h2 style="font-size:24px; margin-bottom:10px;">🔐 লগইন প্রয়োজন</h2>
                <p style="color:var(--text-secondary); font-size:14px;">
                    এই সার্ভারটি প্রাইভেট। অ্যাক্সেস করতে পাসওয়ার্ড দিন।
                </p>
            </div>
            <form method="POST">
                <div class="input-group">
                    <input type="password" name="password" class="input-field" 
                           placeholder="পাসওয়ার্ড লিখুন" required 
                           style="text-align:center; font-size:16px;">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                    <i class="fas fa-sign-in-alt"></i> লগইন
                </button>
            </form>
            <p style="margin-top:20px; font-size:12px; color:var(--text-secondary);">
                ডিফল্ট পাসওয়ার্ড: <code style="background:var(--glass-bg); padding:2px 8px; border-radius:4px;">admin123</code>
            </p>
        </div>
    </div>
    <?php else: ?>
    <!-- মেইন অ্যাপ -->
    <div class="app-container">
        <!-- হেডার -->
        <div class="glass-card">
            <div class="header">
                <div class="logo-section">
                    <div class="logo-icon">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="logo-text">
                        <h1><?= $siteName ?></h1>
                        <p><?= $siteDescription ?></p>
                    </div>
                </div>
                <div class="status-badges">
                    <div class="status-badge online">
                        <i class="fas fa-circle" style="font-size:8px;"></i>
                        অনলাইন
                    </div>
                    <div class="status-badge files">
                        <i class="fas fa-folder"></i>
                        <?= count($items) ?> ফাইল
                    </div>
                    <a href="?logout=1" class="btn btn-sm" style="color:var(--danger);">
                        <i class="fas fa-sign-out-alt"></i> লগআউট
                    </a>
                </div>
            </div>
        </div>

        <!-- নোটিফিকেশন -->
        <?php if ($message): ?>
        <div class="toast <?= $messageType ?>" id="toast" onclick="this.remove()">
            <strong><?= $message ?></strong>
        </div>
        <?php endif; ?>

        <!-- টুলবার ও ব্রেডক্রাম্ব -->
        <div class="glass-card">
            <div class="toolbar">
                <button class="btn btn-primary" onclick="openUploadModal()">
                    <i class="fas fa-cloud-upload-alt"></i> আপলোড
                </button>
                <button class="btn" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> নতুন
                </button>
                <button class="btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> রিফ্রেশ
                </button>
                <button class="btn" onclick="sharePage()">
                    <i class="fas fa-share-alt"></i> শেয়ার
                </button>
            </div>
            
            <div class="breadcrumb">
                <a href="?"><i class="fas fa-home"></i> হোম</a>
                <?php if ($currentDir):
                    $parts = explode('/', $currentDir);
                    $path = '';
                    foreach ($parts as $part):
                        $path .= ($path ? '/' : '') . $part; ?>
                        <span class="separator">/</span>
                        <a href="?dir=<?= urlencode($path) ?>"><?= htmlspecialchars($part) ?></a>
                    <?php endforeach;
                endif; ?>
            </div>
        </div>

        <!-- ফাইল লিস্ট -->
        <div class="file-grid">
            <?php foreach ($items as $item): ?>
            <div class="file-card">
                <div class="file-card-header">
                    <div class="file-icon <?= $item['is_dir'] ? 'folder' : (in_array($item['type'], ['jpg','jpeg','png','gif','svg']) ? 'image' : 'code') ?>">
                        <i class="fas <?= $item['is_dir'] ? 'fa-folder' : getFileIcon($item['type']) ?>"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">
                            <?php if ($item['is_dir']): ?>
                                <a href="?dir=<?= urlencode(($currentDir ? $currentDir . '/' : '') . $item['name']) ?>">
                                    <?= htmlspecialchars($item['name']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($item['name']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="file-meta">
                            <span><?= $item['is_dir'] ? '📁 ফোল্ডার' : humanFileSize($item['size']) ?></span>
                            <span><?= date('d M, h:i A', $item['modified']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="file-actions">
                    <?php if (!$item['is_dir']): ?>
                        <a href="public_files/<?= ($currentDir ? $currentDir . '/' : '') . $item['name'] ?>" 
                           target="_blank" class="btn btn-success btn-sm">
                            <i class="fas fa-play"></i> প্রিভিউ
                        </a>
                        <button class="btn btn-sm" onclick="editFile('<?= htmlspecialchars($item['name']) ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-sm" onclick="renameItem('<?= htmlspecialchars($item['name']) ?>')">
                        <i class="fas fa-tag"></i>
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="deleteItem('<?= htmlspecialchars($item['name']) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($items)): ?>
        <div class="glass-card">
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>কোনো ফাইল নেই</h3>
                <p style="color:var(--text-secondary);">এই ফোল্ডারটি খালি। উপরের বাটন থেকে ফাইল আপলোড করুন।</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- আপলোড মোডাল -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">📤 ফাইল আপলোড</h3>
                <button class="close-btn" onclick="closeModal('uploadModal')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload">
                <div class="upload-dropzone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <h3>ক্লিক করুন অথবা ফাইল ড্র্যাগ করুন</h3>
                    <p style="color:var(--text-secondary);">সব ধরনের ফাইল সাপোর্টেড</p>
                    <input type="file" name="files[]" id="fileInput" multiple 
                           style="display:none;" onchange="handleFiles(this.files)">
                </div>
                <div id="selectedFilesList" style="margin-top:15px;"></div>
                <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn" onclick="closeModal('uploadModal')">বাতিল</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> আপলোড
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- এডিট মোডাল -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">✏️ ফাইল এডিটর</h3>
                <button class="close-btn" onclick="closeModal('editModal')">✕</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="file" id="editFileName">
                <div class="input-group">
                    <textarea name="content" class="input-field" id="editor" 
                              placeholder="লোড হচ্ছে..."></textarea>
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn" onclick="closeModal('editModal')">বাতিল</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> সংরক্ষণ
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // গ্লোবাল ফাংশন
        function openUploadModal() {
            document.getElementById('uploadModal').classList.add('show');
        }

        function openCreateModal() {
            const name = prompt('নাম লিখুন:');
            if (!name) return;
            const type = confirm('ফোল্ডার তৈরি করতে OK, ফাইল তৈরি করতে Cancel চাপুন') ? 'folder' : 'file';
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="name" value="${name}">
                <input type="hidden" name="type" value="${type}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        function handleFiles(files) {
            const list = document.getElementById('selectedFilesList');
            list.innerHTML = Array.from(files).map(file => `
                <div style="display:flex; justify-content:space-between; align-items:center; 
                            padding:10px; background:var(--glass-bg); border-radius:8px; margin:5px 0;">
                    <span><i class="fas fa-file"></i> ${file.name}</span>
                    <span style="color:var(--text-secondary); font-size:12px;">${formatSize(file.size)}</span>
                </div>
            `).join('');
        }

        async function editFile(filename) {
            document.getElementById('editFileName').value = filename;
            document.getElementById('editor').value = 'লোড হচ্ছে...';
            document.getElementById('editModal').classList.add('show');
            
            try {
                const response = await fetch(`?action=read&file=${encodeURIComponent(filename)}&dir=<?= urlencode($currentDir) ?>`);
                const data = await response.json();
                if (data.success) {
                    document.getElementById('editor').value = data.content;
                }
            } catch (error) {
                alert('ফাইল লোড করা যায়নি');
            }
        }

        function renameItem(oldName) {
            const newName = prompt('নতুন নাম লিখুন:', oldName);
            if (!newName || newName === oldName) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="oldname" value="${oldName}">
                <input type="hidden" name="newname" value="${newName}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deleteItem(name) {
            if (!confirm(`"${name}" ডিলিট করতে চান? এই কাজটি আনডু করা যাবে না!`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="file" value="${name}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function sharePage() {
            const url = window.location.href;
            if (navigator.share) {
                navigator.share({
                    title: '<?= $siteName ?>',
                    text: 'আমার পাবলিক সার্ভার ভিজিট করুন!',
                    url: url
                });
            } else {
                navigator.clipboard.writeText(url);
                alert('লিংক কপি করা হয়েছে: ' + url);
            }
        }

        function formatSize(bytes) {
            if (bytes === 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
        }

        // ড্র্যাগ অ্যান্ড ড্রপ
        document.addEventListener('dragover', (e) => e.preventDefault());
        document.addEventListener('drop', (e) => {
            e.preventDefault();
            document.getElementById('fileInput').files = e.dataTransfer.files;
            handleFiles(e.dataTransfer.files);
            openUploadModal();
        });

        // কীবোর্ড শর্টকাট
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
            }
        });

        // অটো-হাইড টোস্ট
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) toast.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>
