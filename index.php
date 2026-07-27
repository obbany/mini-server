<?php
// ==================== পাবলিক মিনি সার্ভার v2.0 ====================
error_reporting(0);
ini_set('display_errors', 0);

// বেসিক কনফিগারেশন
$baseDir = __DIR__ . '/public_files';
$adminPassword = 'admin123'; // চেঞ্জ করুন
$sessionLifetime = 3600; // 1 ঘন্টা

// ফোল্ডার তৈরি
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
    file_put_contents($baseDir . '/index.html', '');
}

// সেশন স্টার্ট
session_start();

// বর্তমান ডিরেক্টরি
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

// লগইন চেক
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (isset($_POST['password']) && $_POST['password'] === $adminPassword) {
    $_SESSION['logged_in'] = true;
    $isLoggedIn = true;
}

// অ্যাকশন হ্যান্ডলিং
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload' && !empty($_FILES['files']['name'][0])) {
        $uploaded = 0;
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;
            
            $fileName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_FILES['files']['name'][$key]);
            $targetPath = $targetDir . '/' . $fileName;
            
            // ডুপ্লিকেট চেক
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
                
                // জিপ এক্সট্র্যাক্ট
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
            $message = "✅ {$uploaded} ফাইল আপলোড সফল!";
            $messageType = 'success';
        }
    }
    
    if ($action === 'delete' && isset($_POST['file'])) {
        $file = $_POST['file'];
        $filePath = $targetDir . '/' . basename($file);
        if (file_exists($filePath)) {
            is_dir($filePath) ? rrmdir($filePath) : unlink($filePath);
            $message = "✅ ডিলিট সফল!";
            $messageType = 'success';
        }
    }
    
    if ($action === 'rename' && isset($_POST['oldname']) && isset($_POST['newname'])) {
        $oldName = $_POST['oldname'];
        $newName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['newname']);
        $oldPath = $targetDir . '/' . $oldName;
        $newPath = $targetDir . '/' . $newName;
        
        if (file_exists($oldPath) && !file_exists($newPath) && rename($oldPath, $newPath)) {
            $message = "✅ রিনেম সফল!";
            $messageType = 'success';
        }
    }
    
    if ($action === 'create' && isset($_POST['name'])) {
        $name = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['name']);
        $type = $_POST['type'] ?? 'file';
        $newPath = $targetDir . '/' . $name;
        
        if (!file_exists($newPath)) {
            $type === 'folder' ? mkdir($newPath, 0755, true) : file_put_contents($newPath, '');
            $message = "✅ তৈরি সফল!";
            $messageType = 'success';
        }
    }
    
    if ($action === 'save' && isset($_POST['file']) && isset($_POST['content'])) {
        $filePath = $targetDir . '/' . basename($_POST['file']);
        if (file_exists($filePath) && file_put_contents($filePath, $_POST['content'])) {
            $message = "✅ সেভ সফল!";
            $messageType = 'success';
        }
    }
}

// ফাইল লিস্টিং
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
            'type' => $isDir ? 'folder' : strtolower(pathinfo($item, PATHINFO_EXTENSION))
        ];
    }
    
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
}

// AJAX হ্যান্ডলিং
if (isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $filePath = $targetDir . '/' . basename($_GET['file']);
    if (file_exists($filePath) && is_file($filePath)) {
        echo json_encode(['success' => true, 'content' => file_get_contents($filePath)]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ইউটিলিটি ফাংশন
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
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 পাবলিক মিনি সার্ভার - ফ্রেন্ড সার্কেল</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #8b5cf6;
            --accent2: #ec4899;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #334155;
            --glass: rgba(255,255,255,0.05);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            color: var(--text);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* গ্লাসমর্ফিজম হেডার */
        .header {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(139, 92, 246, 0.1), transparent, rgba(236, 72, 153, 0.1), transparent);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            100% { transform: rotate(360deg); }
        }
        
        .header-content {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .logo {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #8b5cf6, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            font-size: 40px;
            filter: drop-shadow(0 0 20px rgba(139, 92, 246, 0.5));
        }
        
        .status-badges {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .badge {
            background: var(--glass);
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .badge.online { border-color: var(--success); color: var(--success); }
        .badge.public { border-color: var(--accent); color: var(--accent2); }
        
        /* ফ্লোটিং অ্যাকশন বার */
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .fab {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #8b5cf6, #ec4899);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .fab:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 15px 40px rgba(139, 92, 246, 0.6);
        }
        
        .fab-menu {
            display: none;
            flex-direction: column;
            gap: 10px;
        }
        
        .fab-menu.show {
            display: flex;
        }
        
        .fab-item {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--text);
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* টুলবার */
        .toolbar {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--glass);
            color: var(--text);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn:hover {
            background: var(--accent);
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        .btn-glass {
            background: rgba(139, 92, 246, 0.2);
            border-color: var(--accent);
        }
        
        /* ফাইল কার্ড গ্রিড */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        
        .file-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .file-card:hover {
            border-color: var(--accent);
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }
        
        .file-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .file-icon {
            font-size: 40px;
            background: var(--glass);
            border-radius: 12px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 600;
            font-size: 16px;
            word-break: break-word;
        }
        
        .file-meta {
            font-size: 12px;
            color: var(--muted);
            margin-top: 5px;
        }
        
        .file-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--glass);
            color: var(--text);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .action-btn:hover {
            background: var(--accent);
            border-color: var(--accent);
        }
        
        /* নোটিফিকেশন টোস্ট */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            padding: 15px 25px;
            border-radius: 12px;
            animation: slideIn 0.3s ease;
            backdrop-filter: blur(20px);
        }
        
        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .toast.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
        }
        
        .toast.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--danger);
        }
        
        /* লগইন মোডাল */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            z-index: 999;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show { display: flex; }
        
        .modal-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            width: 90%;
            max-width: 400px;
            animation: scaleUp 0.3s ease;
        }
        
        @keyframes scaleUp {
            from { transform: scale(0.9); }
            to { transform: scale(1); }
        }
        
        .input-field {
            width: 100%;
            padding: 12px 16px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 14px;
            margin: 10px 0;
        }
        
        .input-field:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        /* প্রোগ্রেস বার */
        .upload-progress {
            background: var(--border);
            height: 4px;
            border-radius: 2px;
            margin: 10px 0;
        }
        
        .upload-progress-fill {
            background: linear-gradient(90deg, #8b5cf6, #ec4899);
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s;
        }
        
        /* রেস্পন্সিভ */
        @media (max-width: 768px) {
            .file-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .fab-container {
                bottom: 20px;
                right: 20px;
            }
            
            .fab {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
        }
        
        /* লোডিং অ্যানিমেশন */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php if (!$isLoggedIn): ?>
    <!-- লগইন স্ক্রিন -->
    <div class="modal show" id="loginModal">
        <div class="modal-card">
            <div style="text-align:center; margin-bottom:30px;">
                <i class="fas fa-shield-haltered" style="font-size:60px; background:linear-gradient(135deg, #8b5cf6, #ec4899); -webkit-background-clip:text; -webkit-text-fill-color:transparent;"></i>
                <h2 style="margin-top:15px;">🔐 লগইন</h2>
                <p style="color:var(--muted); margin-top:10px;">পাবলিক সার্ভার অ্যাক্সেস করতে পাসওয়ার্ড দিন</p>
            </div>
            <form method="POST">
                <input type="password" name="password" class="input-field" placeholder="পাসওয়ার্ড লিখুন" required>
                <button type="submit" class="btn btn-glass" style="width:100%; margin-top:20px; justify-content:center;">
                    <i class="fas fa-lock-open"></i> লগইন
                </button>
            </form>
            <p style="text-align:center; color:var(--muted); margin-top:20px; font-size:12px;">
                ডিফল্ট পাসওয়ার্ড: admin123
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="container">
        <!-- হেডার -->
        <div class="header">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-globe logo-icon"></i>
                    <span>পাবলিক সার্ভার</span>
                </div>
                <div class="status-badges">
                    <div class="badge online">
                        <i class="fas fa-circle" style="font-size:8px;"></i>
                        অনলাইন
                    </div>
                    <div class="badge public">
                        <i class="fas fa-users"></i>
                        পাবলিক
                    </div>
                    <div class="badge">
                        <i class="fas fa-folder"></i>
                        <?= count($items) ?> ফাইল
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="toast <?= $messageType ?>" id="toast" onclick="this.remove()">
            <?= $message ?>
        </div>
        <?php endif; ?>

        <!-- টুলবার -->
        <div class="toolbar">
            <button class="btn btn-glass" onclick="document.getElementById('fileInput').click()">
                <i class="fas fa-cloud-upload-alt"></i> আপলোড
            </button>
            <button class="btn" onclick="createNew()">
                <i class="fas fa-plus-circle"></i> নতুন
            </button>
            <button class="btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> রিফ্রেশ
            </button>
            <div style="flex:1;"></div>
            <!-- ব্রেডক্রাম্ব -->
            <div style="display:flex; align-items:center; gap:8px; color:var(--muted);">
                <a href="?" style="color:var(--accent); text-decoration:none;">
                    <i class="fas fa-home"></i>
                </a>
                <?php if ($currentDir):
                    $parts = explode('/', $currentDir);
                    $path = '';
                    foreach ($parts as $part):
                        $path .= ($path ? '/' : '') . $part; ?>
                        <span>/</span>
                        <a href="?dir=<?= urlencode($path) ?>" style="color:var(--accent); text-decoration:none;">
                            <?= htmlspecialchars($part) ?>
                        </a>
                    <?php endforeach;
                endif; ?>
            </div>
        </div>

        <!-- ফাইল গ্রিড -->
        <div class="file-grid">
            <?php foreach ($items as $item): ?>
            <div class="file-card">
                <div class="file-card-header">
                    <div class="file-icon">
                        <i class="fas <?= $item['is_dir'] ? 'fa-folder text-warning' : getIcon($item['type']) ?>" 
                           style="font-size:30px;"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">
                            <?php if ($item['is_dir']): ?>
                                <a href="?dir=<?= urlencode(($currentDir ? $currentDir . '/' : '') . $item['name']) ?>" 
                                   style="color:var(--text); text-decoration:none;">
                                    <?= htmlspecialchars($item['name']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($item['name']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="file-meta">
                            <?= $item['is_dir'] ? '📁 ফোল্ডার' : '📄 ' . humanFileSize($item['size']) ?>
                            <br>
                            <?= date('d M Y, h:i A', $item['modified']) ?>
                        </div>
                    </div>
                </div>
                <div class="file-actions">
                    <?php if (!$item['is_dir']): ?>
                        <a href="public_files/<?= ($currentDir ? $currentDir . '/' : '') . $item['name'] ?>" 
                           target="_blank" class="action-btn" title="লাইভ প্রিভিউ">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button class="action-btn" onclick="editFile('<?= $item['name'] ?>')" title="এডিট">
                            <i class="fas fa-edit"></i>
                        </button>
                    <?php endif; ?>
                    <button class="action-btn" onclick="renameItem('<?= $item['name'] ?>')" title="রিনেম">
                        <i class="fas fa-pencil-alt"></i>
                    </button>
                    <button class="action-btn" onclick="deleteItem('<?= $item['name'] ?>')" 
                            style="color:var(--danger);" title="ডিলিট">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($items)): ?>
        <div style="text-align:center; padding:60px; color:var(--muted);">
            <i class="fas fa-cloud-upload-alt" style="font-size:80px; margin-bottom:20px; opacity:0.3;"></i>
            <h3>ফোল্ডার খালি</h3>
            <p>আপলোড বাটনে ক্লিক করে ফাইল শেয়ার করুন</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ফ্লোটিং অ্যাকশন বাটন -->
    <div class="fab-container">
        <div class="fab-menu" id="fabMenu">
            <button class="fab-item" onclick="shareLink()" title="শেয়ার লিংক">
                <i class="fas fa-share-alt"></i>
            </button>
            <button class="fab-item" onclick="document.getElementById('fileInput').click()" title="আপলোড">
                <i class="fas fa-upload"></i>
            </button>
            <button class="fab-item" onclick="location.reload()" title="রিফ্রেশ">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
        <button class="fab" onclick="toggleFab()">
            <i class="fas fa-plus" id="fabIcon"></i>
        </button>
    </div>

    <!-- হিডেন ফাইল ইনপুট -->
    <form id="uploadForm" method="POST" enctype="multipart/form-data" style="display:none;">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="files[]" id="fileInput" multiple 
               onchange="handleUpload(this.files)">
    </form>

    <script>
        function toggleFab() {
            const menu = document.getElementById('fabMenu');
            const icon = document.getElementById('fabIcon');
            menu.classList.toggle('show');
            icon.className = menu.classList.contains('show') ? 'fas fa-times' : 'fas fa-plus';
        }

        function handleUpload(files) {
            if (!files.length) return;
            
            const formData = new FormData(document.getElementById('uploadForm'));
            for (let file of files) {
                formData.append('files[]', file);
            }
            
            // আপলোড প্রোগ্রেস দেখান
            const loadingToast = document.createElement('div');
            loadingToast.className = 'toast';
            loadingToast.innerHTML = '<div class="loading"></div> আপলোড হচ্ছে...';
            document.body.appendChild(loadingToast);
            
            fetch('', { method: 'POST', body: formData })
                .then(() => {
                    loadingToast.remove();
                    location.reload();
                });
        }

        function shareLink() {
            const url = window.location.href;
            if (navigator.share) {
                navigator.share({
                    title: 'পাবলিক সার্ভার',
                    text: 'আমার পাবলিক সার্ভার ভিজিট করুন!',
                    url: url
                });
            } else {
                navigator.clipboard.writeText(url);
                alert('লিংক কপি করা হয়েছে: ' + url);
            }
        }

        function createNew() {
            const name = prompt('নাম লিখুন:');
            if (!name) return;
            const type = confirm('ফোল্ডার তৈরি করতে OK চাপুন') ? 'folder' : 'file';
            
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

        function editFile(name) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="file" value="${name}">
                <input type="hidden" name="content" value="${prompt('কন্টেন্ট:', '')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function renameItem(oldName) {
            const newName = prompt('নতুন নাম:', oldName);
            if (!newName) return;
            
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
            if (!confirm(`${name} ডিলিট করবেন?`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="file" value="${name}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // ড্র্যাগ & ড্রপ
        document.addEventListener('dragover', (e) => {
            e.preventDefault();
        });
        
        document.addEventListener('drop', (e) => {
            e.preventDefault();
            handleUpload(e.dataTransfer.files);
        });

        // টোস্ট অটো-হাইড
        setTimeout(() => {
            const toast = document.getElementById('toast');
            if (toast) toast.remove();
        }, 3000);
    </script>
</body>
</html>
<?php
function getIcon($type) {
    $icons = [
        'php' => 'fa-php',
        'html' => 'fa-html5',
        'css' => 'fa-css3-alt',
        'js' => 'fa-js',
        'jpg' => 'fa-image',
        'jpeg' => 'fa-image',
        'png' => 'fa-image',
        'gif' => 'fa-image',
        'pdf' => 'fa-file-pdf',
        'zip' => 'fa-file-archive',
        'mp4' => 'fa-video',
        'mp3' => 'fa-music',
    ];
    return $icons[$type] ?? 'fa-file';
}
?>
