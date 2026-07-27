<?php
/**
 * মিনি সার্ভার - অ্যাডভান্সড UI ভার্সন
 * GitHub: https://github.com/obbany/mini-server
 */

// কনফিগারেশন
$baseDir = __DIR__ . '/public_files';
$adminPassword = 'admin123';
$siteName = '🌟 মিনি সার্ভার';
$siteDesc = 'আপনার পার্সোনাল ক্লাউড স্টোরেজ';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0755, true);
}

session_start();

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

// অ্যাকশন হ্যান্ডলিং
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload' && !empty($_FILES['files']['name'][0])) {
        $uploaded = 0;
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;
            
            $fileName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_FILES['files']['name'][$key]);
            $targetPath = $targetDir . '/' . $fileName;
            
            $counter = 1;
            $info = pathinfo($fileName);
            while (file_exists($targetPath)) {
                $fileName = $info['filename'] . '_' . $counter . '.' . $info['extension'];
                $targetPath = $targetDir . '/' . $fileName;
                $counter++;
            }
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $uploaded++;
                if (strtolower($info['extension']) === 'zip' && class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($targetPath) === true) {
                        $extractDir = $targetDir . '/' . $info['filename'];
                        if (!is_dir($extractDir)) mkdir($extractDir, 0755, true);
                        $zip->extractTo($extractDir);
                        $zip->close();
                        unlink($targetPath);
                    }
                }
            }
        }
        $msg = "✅ {$uploaded} টি ফাইল আপলোড সফল!";
        $msgType = 'success';
    }
    
    if ($action === 'delete' && isset($_POST['file'])) {
        $filePath = $targetDir . '/' . basename($_POST['file']);
        if (file_exists($filePath)) {
            is_dir($filePath) ? delTree($filePath) : unlink($filePath);
            $msg = "✅ ডিলিট সফল!";
            $msgType = 'success';
        }
    }
    
    if ($action === 'rename' && isset($_POST['old']) && isset($_POST['new'])) {
        $old = $targetDir . '/' . $_POST['old'];
        $new = $targetDir . '/' . preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['new']);
        if (file_exists($old) && !file_exists($new) && rename($old, $new)) {
            $msg = "✅ রিনেম সফল!";
            $msgType = 'success';
        }
    }
    
    if ($action === 'create' && isset($_POST['name'])) {
        $name = preg_replace('/[^a-zA-Z0-9._\-\s]/', '', $_POST['name']);
        $path = $targetDir . '/' . $name;
        if (!file_exists($path)) {
            ($_POST['type'] ?? 'file') === 'folder' ? mkdir($path, 0755, true) : file_put_contents($path, '');
            $msg = "✅ তৈরি সফল!";
            $msgType = 'success';
        }
    }
    
    if ($action === 'save' && isset($_POST['file']) && isset($_POST['content'])) {
        $path = $targetDir . '/' . basename($_POST['file']);
        if (file_exists($path) && file_put_contents($path, $_POST['content'])) {
            $msg = "✅ সেভ সফল!";
            $msgType = 'success';
        }
    }
}

// ফাইল লিস্ট
$items = [];
if (is_dir($targetDir)) {
    foreach (array_diff(scandir($targetDir), ['.', '..']) as $item) {
        $path = $targetDir . '/' . $item;
        $isDir = is_dir($path);
        $items[] = [
            'name' => $item,
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : filesize($path),
            'time' => filemtime($path),
            'ext' => $isDir ? 'folder' : strtolower(pathinfo($item, PATHINFO_EXTENSION))
        ];
    }
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
}

// AJAX
if (isset($_GET['read']) && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $path = $targetDir . '/' . basename($_GET['file']);
    echo json_encode([
        'ok' => file_exists($path),
        'content' => file_exists($path) ? file_get_contents($path) : ''
    ]);
    exit;
}

function delTree($dir) {
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? delTree($p) : unlink($p);
    }
    return rmdir($dir);
}

function sizeFormat($b) {
    if ($b == 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB'];
    return round($b / pow(1024, $i = floor(log($b, 1024))), 1) . ' ' . $u[$i];
}

function icon($ext) {
    $map = [
        'php' => 'php', 'html' => 'html5', 'css' => 'css3', 'js' => 'js',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'pdf' => 'pdf', 'zip' => 'archive', 'rar' => 'archive',
        'mp4' => 'video', 'mp3' => 'audio', 'txt' => 'alt', 'md' => 'alt'
    ];
    return $map[$ext] ?? 'file';
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteName ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg: #0a0a1a;
            --card: #151530;
            --text: #fff;
            --sub: #8888aa;
            --pink: #ff2d78;
            --blue: #4d7cff;
            --green: #00e676;
            --yellow: #ffd600;
            --red: #ff1744;
            --border: #252545;
            --glass: rgba(255,255,255,0.03);
        }

        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            background-image: 
                radial-gradient(ellipse at 20% 20%, rgba(255,45,120,0.1) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(77,124,255,0.1) 0%, transparent 50%);
            color: var(--text);
            min-height: 100vh;
        }

        .app {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        /* হেডার */
        .header {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo i {
            font-size: 36px;
            background: linear-gradient(135deg, var(--pink), var(--blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo h1 {
            font-size: 24px;
            font-weight: 700;
        }

        .logo span {
            font-size: 12px;
            color: var(--sub);
            display: block;
        }

        .badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .badge.on {
            border-color: var(--green);
            color: var(--green);
        }
        .badge.count {
            border-color: var(--blue);
            color: var(--blue);
        }

        .btn {
            padding: 10px 18px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--glass);
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn:hover {
            background: var(--border);
            transform: translateY(-2px);
        }

        .btn-pink {
            background: linear-gradient(135deg, var(--pink), #ff6b9d);
            border: none;
            color: #fff;
        }

        .btn-blue {
            background: linear-gradient(135deg, var(--blue), #6b9fff);
            border: none;
            color: #fff;
        }

        .btn-green {
            background: var(--green);
            border: none;
            color: #000;
        }

        .btn-red {
            background: var(--red);
            border: none;
            color: #fff;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
            border-radius: 8px;
        }

        /* টুলবার */
        .toolbar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            flex-wrap: wrap;
        }

        .breadcrumb a {
            color: var(--blue);
            text-decoration: none;
        }

        .breadcrumb span {
            color: var(--sub);
        }

        /* ফাইল গ্রিড */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s;
        }

        .card:hover {
            border-color: var(--blue);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(77,124,255,0.1);
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--glass);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .card-icon.folder { color: var(--yellow); }
        .card-icon.image { color: var(--green); }
        .card-icon.code { color: var(--blue); }
        .card-icon.pdf { color: var(--red); }

        .card-info {
            flex: 1;
            min-width: 0;
        }

        .card-name {
            font-weight: 600;
            font-size: 15px;
            word-break: break-word;
            margin-bottom: 4px;
        }

        .card-name a {
            color: var(--text);
            text-decoration: none;
        }

        .card-name a:hover {
            color: var(--blue);
        }

        .card-meta {
            font-size: 11px;
            color: var(--sub);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .card-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* মোডাল */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(4px);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            width: 90%;
            max-width: 500px;
            animation: pop 0.3s ease;
        }

        @keyframes pop {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: none;
            color: var(--text);
            cursor: pointer;
            font-size: 16px;
        }

        .input {
            width: 100%;
            padding: 12px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            margin-bottom: 10px;
        }

        .input:focus {
            outline: none;
            border-color: var(--blue);
        }

        textarea.input {
            min-height: 200px;
            font-family: monospace;
            resize: vertical;
        }

        /* টোস্ট */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 200;
            padding: 14px 20px;
            border-radius: 12px;
            background: var(--card);
            border: 1px solid var(--border);
            animation: slide 0.3s ease;
        }

        @keyframes slide {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.ok { border-color: var(--green); }
        .toast.err { border-color: var(--red); }

        /* আপলোড জোন */
        .dropzone {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dropzone:hover {
            border-color: var(--pink);
            background: rgba(255,45,120,0.05);
        }

        .dropzone i {
            font-size: 40px;
            color: var(--pink);
            margin-bottom: 10px;
        }

        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--sub);
        }

        .empty i {
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        @media (max-width: 600px) {
            .header { flex-direction: column; text-align: center; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php if (!$isLoggedIn): ?>
    <div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;">
        <div class="modal-box" style="max-width:380px; text-align:center;">
            <i class="fas fa-lock" style="font-size:50px; background:linear-gradient(135deg, var(--pink), var(--blue)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:20px;"></i>
            <h2 style="margin-bottom:10px;">🔐 লগইন</h2>
            <p style="color:var(--sub); margin-bottom:20px; font-size:14px;">পাসওয়ার্ড দিয়ে প্রবেশ করুন</p>
            <form method="POST">
                <input type="password" name="password" class="input" placeholder="পাসওয়ার্ড" required style="text-align:center;">
                <button type="submit" class="btn btn-pink" style="width:100%; justify-content:center; margin-top:10px;">
                    <i class="fas fa-sign-in-alt"></i> প্রবেশ
                </button>
            </form>
            <p style="font-size:11px; color:var(--sub); margin-top:15px;">ডিফল্ট: admin123</p>
        </div>
    </div>
    <?php else: ?>
    
    <div class="app">
        <!-- হেডার -->
        <div class="header">
            <div class="logo">
                <i class="fas fa-cloud"></i>
                <div>
                    <h1><?= $siteName ?></h1>
                    <span><?= $siteDesc ?></span>
                </div>
            </div>
            <div class="badges">
                <div class="badge on"><i class="fas fa-circle" style="font-size:7px;"></i> অনলাইন</div>
                <div class="badge count"><i class="fas fa-folder"></i> <?= count($items) ?> ফাইল</div>
                <a href="?logout=1" class="btn btn-sm" style="border-color:var(--red); color:var(--red);">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>

        <?php if ($msg): ?>
        <div class="toast <?= $msgType === 'success' ? 'ok' : 'err' ?>" onclick="this.remove()">
            <?= $msg ?>
        </div>
        <?php endif; ?>

        <!-- টুলবার -->
        <div class="toolbar">
            <button class="btn btn-pink btn-sm" onclick="openModal('uploadModal')">
                <i class="fas fa-upload"></i> আপলোড
            </button>
            <button class="btn btn-blue btn-sm" onclick="newItem()">
                <i class="fas fa-plus"></i> নতুন
            </button>
            <button class="btn btn-sm" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i>
            </button>
            <button class="btn btn-sm" onclick="share()">
                <i class="fas fa-share-alt"></i>
            </button>
        </div>

        <!-- ব্রেডক্রাম্ব -->
        <div class="breadcrumb" style="margin-bottom:15px;">
            <a href="?"><i class="fas fa-home"></i> হোম</a>
            <?php if ($currentDir):
                $p = '';
                foreach (explode('/', $currentDir) as $part):
                    $p .= ($p ? '/' : '') . $part; ?>
                    <span>/</span>
                    <a href="?dir=<?= urlencode($p) ?>"><?= htmlspecialchars($part) ?></a>
            <?php endforeach; endif; ?>
        </div>

        <!-- ফাইল গ্রিড -->
        <div class="grid">
            <?php foreach ($items as $item): ?>
            <div class="card">
                <div class="card-top">
                    <div class="card-icon <?= $item['is_dir'] ? 'folder' : (in_array($item['ext'], ['jpg','jpeg','png','gif']) ? 'image' : (in_array($item['ext'], ['php','html','css','js']) ? 'code' : ($item['ext'] === 'pdf' ? 'pdf' : ''))) ?>">
                        <i class="fa<?= $item['is_dir'] ? 's fa-folder' : 'r fa-file-' . icon($item['ext']) ?>"></i>
                    </div>
                    <div class="card-info">
                        <div class="card-name">
                            <?php if ($item['is_dir']): ?>
                                <a href="?dir=<?= urlencode(($currentDir ? $currentDir . '/' : '') . $item['name']) ?>">
                                    📁 <?= htmlspecialchars($item['name']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($item['name']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="card-meta">
                            <span><?= $item['is_dir'] ? 'ফোল্ডার' : sizeFormat($item['size']) ?></span>
                            <span><?= date('d M, h:i A', $item['time']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <?php if (!$item['is_dir']): ?>
                        <a href="public_files/<?= ($currentDir ? $currentDir . '/' : '') . $item['name'] ?>" target="_blank" class="btn btn-green btn-sm">
                            <i class="fas fa-eye"></i> প্রিভিউ
                        </a>
                        <button class="btn btn-sm" onclick="editFile('<?= htmlspecialchars($item['name']) ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-sm" onclick="renameItem('<?= htmlspecialchars($item['name']) ?>')">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="btn btn-red btn-sm" onclick="deleteItem('<?= htmlspecialchars($item['name']) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($items)): ?>
        <div class="empty">
            <i class="fas fa-folder-open"></i>
            <h3>কোনো ফাইল নেই</h3>
            <p>উপরের "আপলোড" বাটনে ক্লিক করে ফাইল যোগ করুন</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- আপলোড মোডাল -->
    <div class="modal" id="uploadModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3>📤 ফাইল আপলোড</h3>
                <button class="modal-close" onclick="closeModal('uploadModal')">✕</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="dropzone" onclick="document.getElementById('fileInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>ক্লিক করুন বা ফাইল ড্র্যাগ করুন</p>
                    <input type="file" name="files[]" id="fileInput" multiple style="display:none;" onchange="showFiles(this.files)">
                </div>
                <div id="fileList" style="margin:10px 0;"></div>
                <button type="submit" class="btn btn-pink" style="width:100%; justify-content:center;">
                    <i class="fas fa-upload"></i> আপলোড করুন
                </button>
            </form>
        </div>
    </div>

    <!-- এডিট মোডাল -->
    <div class="modal" id="editModal">
        <div class="modal-box" style="max-width:700px;">
            <div class="modal-head">
                <h3>✏️ এডিটর</h3>
                <button class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="file" id="editFileName">
                <textarea name="content" class="input" id="editor" placeholder="লোড হচ্ছে..."></textarea>
                <button type="submit" class="btn btn-blue" style="width:100%; justify-content:center; margin-top:10px;">
                    <i class="fas fa-save"></i> সেভ
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('show'); }
        function closeModal(id) { document.getElementById(id).classList.remove('show'); }

        function showFiles(files) {
            document.getElementById('fileList').innerHTML = Array.from(files).map(f => 
                `<div style="padding:8px; background:var(--glass); border-radius:8px; margin:4px 0; font-size:13px;">
                    📄 ${f.name} (${(f.size/1024).toFixed(1)} KB)
                </div>`
            ).join('');
        }

        function newItem() {
            const name = prompt('নাম লিখুন:');
            if (!name) return;
            const type = confirm('ফোল্ডার? (OK = ফোল্ডার, Cancel = ফাইল)') ? 'folder' : 'file';
            submitForm({action:'create', name:name, type:type});
        }

        async function editFile(name) {
            document.getElementById('editFileName').value = name;
            document.getElementById('editor').value = 'লোড হচ্ছে...';
            openModal('editModal');
            try {
                const r = await fetch(`?read=1&file=${encodeURIComponent(name)}&dir=<?= urlencode($currentDir) ?>`);
                const d = await r.json();
                if (d.ok) document.getElementById('editor').value = d.content;
            } catch(e) { alert('লোড করা যায়নি'); }
        }

        function renameItem(old) {
            const nw = prompt('নতুন নাম:', old);
            if (nw && nw !== old) submitForm({action:'rename', old:old, 'new':nw});
        }

        function deleteItem(name) {
            if (confirm(`"${name}" ডিলিট করবেন?`)) submitForm({action:'delete', file:name});
        }

        function submitForm(data) {
            const f = document.createElement('form');
            f.method = 'POST';
            Object.entries(data).forEach(([k,v]) => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = k; i.value = v;
                f.appendChild(i);
            });
            document.body.appendChild(f);
            f.submit();
        }

        function share() {
            const url = window.location.href;
            navigator.clipboard ? (navigator.clipboard.writeText(url), alert('লিংক কপি হয়েছে!')) : prompt('লিংক:', url);
        }

        // ড্র্যাগ & ড্রপ
        document.addEventListener('dragover', e => e.preventDefault());
        document.addEventListener('drop', e => {
            e.preventDefault();
            document.getElementById('fileInput').files = e.dataTransfer.files;
            showFiles(e.dataTransfer.files);
            openModal('uploadModal');
        });

        // টোস্ট হাইড
        setTimeout(() => {
            const t = document.querySelector('.toast');
            if (t) t.remove();
        }, 4000);

        // ESC ক্লোজ
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
        });
    </script>
</body>
</html>
