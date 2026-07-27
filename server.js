const express = require('express');
const cors = require('cors');
const bodyParser = require('body-parser');
const compression = require('compression');
const helmet = require('helmet');
const path = require('path');
const fs = require('fs-extra');
const { spawn } = require('child_process');
const si = require('systeminformation');
const archiver = require('archiver');
const multer = require('multer');
const { exec } = require('child_process');

const app = express();
const PORT = process.env.PORT || 3000;
const WWW_ROOT = path.join(__dirname, 'www');
const DOMAINS_FILE = path.join(__dirname, 'domains.json');

// Ensure directories exist
fs.ensureDirSync(WWW_ROOT);
fs.ensureFileSync(DOMAINS_FILE);

// Load domains
let domains = [];
try {
    domains = JSON.parse(fs.readFileSync(DOMAINS_FILE, 'utf-8'));
} catch (error) {
    domains = [];
}

// Middleware
app.use(helmet({
    contentSecurityPolicy: false,
    crossOriginEmbedderPolicy: false
}));
app.use(compression());
app.use(cors({
    origin: '*',
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization']
}));
app.use(bodyParser.json({ limit: '50mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));

// Static files
app.use(express.static(path.join(__dirname, 'public'), {
    maxAge: '1d',
    etag: true
}));

// ===== DOMAIN API =====
app.get('/api/domains', (req, res) => {
    res.json({ success: true, domains });
});

app.post('/api/domains/add', (req, res) => {
    try {
        const { domain, site, tunnel } = req.body;
        if (!domain) {
            return res.status(400).json({ error: 'Domain is required' });
        }
        const newDomain = {
            id: Date.now().toString(),
            domain: domain.trim(),
            site: site || '',
            tunnel: tunnel || '',
            status: 'active',
            createdAt: new Date().toISOString()
        };
        domains.push(newDomain);
        fs.writeFileSync(DOMAINS_FILE, JSON.stringify(domains, null, 2));
        res.json({ success: true, domain: newDomain });
    } catch (error) {
        res.status(500).json({ error: 'Failed to add domain' });
    }
});

app.delete('/api/domains/remove/:id', (req, res) => {
    try {
        const { id } = req.params;
        domains = domains.filter(d => d.id !== id);
        fs.writeFileSync(DOMAINS_FILE, JSON.stringify(domains, null, 2));
        res.json({ success: true });
    } catch (error) {
        res.status(500).json({ error: 'Failed to remove domain' });
    }
});

// ===== UPDATE API =====
app.get('/api/update/check', (req, res) => {
    const currentVersion = require('./package.json').version || '1.0.0';
    exec('git ls-remote --tags https://github.com/obbany/mini-server.git | grep -o "v[0-9]*\\.[0-9]*\\.[0-9]*" | sort -V | tail -n1', 
        (error, stdout) => {
            const latestVersion = stdout.trim() || currentVersion;
            res.json({
                current: currentVersion,
                latest: latestVersion,
                hasUpdate: latestVersion !== currentVersion
            });
        }
    );
});

app.post('/api/update/install', (req, res) => {
    res.json({ success: true, message: 'Update started' });
    exec('cd ~/termux-cpanel && git pull && npm install && pm2 restart termux-cpanel', 
        (error) => {
            if (error) console.error('Update failed:', error);
            else console.log('Update successful');
        }
    );
});

// ===== HEALTH API =====
app.get('/api/health', async (req, res) => {
    try {
        const [cpu, mem, fsSize, uptime] = await Promise.all([
            si.cpuCurrentSpeed(),
            si.mem(),
            si.fsSize(),
            si.time()
        ]);
        const webRootStats = await fs.stat(WWW_ROOT);
        const freeSpace = fsSize.find(drive => drive.mount === '/')?.available || 0;
        const totalSpace = fsSize.find(drive => drive.mount === '/')?.size || 0;
        res.json({
            status: 'online',
            cpu: { usage: 0 },
            memory: {
                total: mem.total,
                used: mem.active,
                free: mem.free,
                usagePercentage: ((mem.active / mem.total) * 100).toFixed(1)
            },
            storage: {
                total: totalSpace,
                free: freeSpace,
                used: totalSpace - freeSpace,
                usagePercentage: (((totalSpace - freeSpace) / totalSpace) * 100).toFixed(1)
            },
            uptime: {
                system: uptime.uptime,
                node: process.uptime()
            },
            server: {
                hostname: require('os').hostname(),
                platform: process.platform,
                nodeVersion: process.version,
                port: PORT
            },
            domains: domains
        });
    } catch (error) {
        res.status(500).json({ error: 'Failed to fetch system health' });
    }
});

// ===== SITES API =====
app.get('/api/sites', async (req, res) => {
    try {
        const sites = [];
        if (fs.existsSync(WWW_ROOT)) {
            const items = await fs.readdir(WWW_ROOT);
            for (const item of items) {
                const itemPath = path.join(WWW_ROOT, item);
                const stat = await fs.stat(itemPath);
                if (stat.isDirectory()) {
                    const gitDir = path.join(itemPath, '.git');
                    const isGit = fs.existsSync(gitDir);
                    const hasIndex = ['index.html', 'index.htm', 'index.php', 'index.js'].some(file => 
                        fs.existsSync(path.join(itemPath, file))
                    );
                    sites.push({
                        name: item,
                        path: `/sites/${item}`,
                        isGit: isGit,
                        hasIndex: hasIndex,
                        size: stat.size,
                        created: stat.birthtime,
                        modified: stat.mtime
                    });
                }
            }
        }
        res.json({ success: true, sites });
    } catch (error) {
        res.status(500).json({ error: 'Failed to list sites' });
    }
});

app.post('/api/sites/create', async (req, res) => {
    try {
        const { name } = req.body;
        if (!name || !/^[a-zA-Z0-9-_]+$/.test(name)) {
            return res.status(400).json({ error: 'Invalid site name' });
        }
        const sitePath = path.join(WWW_ROOT, name);
        if (fs.existsSync(sitePath)) {
            return res.status(400).json({ error: 'Site already exists' });
        }
        await fs.ensureDir(sitePath);
        const defaultHTML = `<!DOCTYPE html>
<html>
<head><title>Welcome to ${name}</title>
<style>body{font-family:sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#0f172a;color:#e2e8f0;}h1{color:#818cf8;}</style>
</head>
<body><h1>🚀 Welcome to ${name}</h1><p>Your site is live!</p><p>Hosted on Termux CPanel.</p></body>
</html>`;
        await fs.writeFile(path.join(sitePath, 'index.html'), defaultHTML);
        res.json({ success: true, site: { name, path: `/sites/${name}` } });
    } catch (error) {
        res.status(500).json({ error: 'Failed to create site' });
    }
});

app.delete('/api/sites/:name', async (req, res) => {
    try {
        const { name } = req.params;
        const sitePath = path.join(WWW_ROOT, name);
        if (!fs.existsSync(sitePath)) {
            return res.status(404).json({ error: 'Site not found' });
        }
        await fs.remove(sitePath);
        res.json({ success: true });
    } catch (error) {
        res.status(500).json({ error: 'Failed to delete site' });
    }
});

// ===== FILE API =====
app.post('/api/files/list', async (req, res) => {
    try {
        const { path: dirPath } = req.body;
        const fullPath = path.join(WWW_ROOT, dirPath || '');
        const normalizedPath = path.normalize(fullPath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        if (!fs.existsSync(normalizedPath)) {
            return res.status(404).json({ error: 'Directory not found' });
        }
        const stats = await fs.stat(normalizedPath);
        if (!stats.isDirectory()) {
            return res.status(400).json({ error: 'Not a directory' });
        }
        const files = await fs.readdir(normalizedPath);
        const items = await Promise.all(files.map(async (file) => {
            const filePath = path.join(normalizedPath, file);
            try {
                const stat = await fs.stat(filePath);
                return {
                    name: file,
                    path: path.relative(WWW_ROOT, filePath),
                    fullPath: filePath,
                    isDirectory: stat.isDirectory(),
                    size: stat.size,
                    modified: stat.mtime,
                    permissions: stat.mode
                };
            } catch (err) { return null; }
        }));
        res.json({ success: true, currentPath: path.relative(WWW_ROOT, normalizedPath) || '/', items: items.filter(item => item !== null) });
    } catch (error) {
        res.status(500).json({ error: 'Failed to list files' });
    }
});

app.post('/api/files/create', async (req, res) => {
    try {
        const { path: dirPath, name, type } = req.body;
        const fullPath = path.join(WWW_ROOT, dirPath || '', name);
        const normalizedPath = path.normalize(fullPath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        if (fs.existsSync(normalizedPath)) {
            return res.status(400).json({ error: 'File or directory already exists' });
        }
        if (type === 'directory') {
            await fs.ensureDir(normalizedPath);
        } else {
            await fs.writeFile(normalizedPath, '');
        }
        res.json({ success: true, path: path.relative(WWW_ROOT, normalizedPath) });
    } catch (error) {
        res.status(500).json({ error: 'Failed to create file' });
    }
});

app.post('/api/files/rename', async (req, res) => {
    try {
        const { oldPath, newName } = req.body;
        const oldFullPath = path.join(WWW_ROOT, oldPath);
        const newFullPath = path.join(path.dirname(oldFullPath), newName);
        const normalizedOld = path.normalize(oldFullPath);
        const normalizedNew = path.normalize(newFullPath);
        if (!normalizedOld.startsWith(WWW_ROOT) || !normalizedNew.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        if (!fs.existsSync(normalizedOld)) {
            return res.status(404).json({ error: 'File not found' });
        }
        if (fs.existsSync(normalizedNew)) {
            return res.status(400).json({ error: 'Target already exists' });
        }
        await fs.rename(normalizedOld, normalizedNew);
        res.json({ success: true, newPath: path.relative(WWW_ROOT, normalizedNew) });
    } catch (error) {
        res.status(500).json({ error: 'Failed to rename file' });
    }
});

app.post('/api/files/delete', async (req, res) => {
    try {
        const { paths } = req.body;
        for (const filePath of paths) {
            const fullPath = path.join(WWW_ROOT, filePath);
            const normalizedPath = path.normalize(fullPath);
            if (!normalizedPath.startsWith(WWW_ROOT)) {
                return res.status(403).json({ error: 'Access denied for ' + filePath });
            }
            if (fs.existsSync(normalizedPath)) {
                await fs.remove(normalizedPath);
            }
        }
        res.json({ success: true });
    } catch (error) {
        res.status(500).json({ error: 'Failed to delete files' });
    }
});

app.get('/api/files/read/:path(*)', async (req, res) => {
    try {
        const filePath = path.join(WWW_ROOT, req.params.path);
        const normalizedPath = path.normalize(filePath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        if (!fs.existsSync(normalizedPath)) {
            return res.status(404).json({ error: 'File not found' });
        }
        const stat = await fs.stat(normalizedPath);
        if (stat.isDirectory()) {
            return res.status(400).json({ error: 'Cannot read directory' });
        }
        const content = await fs.readFile(normalizedPath, 'utf-8');
        const extension = path.extname(normalizedPath).slice(1);
        res.json({ success: true, content, name: path.basename(normalizedPath), path: req.params.path, extension, size: stat.size, modified: stat.mtime });
    } catch (error) {
        res.status(500).json({ error: 'Failed to read file' });
    }
});

app.post('/api/files/write', async (req, res) => {
    try {
        const { path: filePath, content } = req.body;
        const fullPath = path.join(WWW_ROOT, filePath);
        const normalizedPath = path.normalize(fullPath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        await fs.writeFile(normalizedPath, content);
        res.json({ success: true });
    } catch (error) {
        res.status(500).json({ error: 'Failed to write file' });
    }
});

// File upload
const storage = multer.diskStorage({
    destination: function (req, file, cb) {
        const uploadPath = path.join(WWW_ROOT, req.body.path || '');
        const normalizedPath = path.normalize(uploadPath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return cb(new Error('Access denied'));
        }
        fs.ensureDirSync(normalizedPath);
        cb(null, normalizedPath);
    },
    filename: function (req, file, cb) {
        cb(null, file.originalname);
    }
});

const upload = multer({ storage, limits: { fileSize: 100 * 1024 * 1024 } });

app.post('/api/files/upload', upload.array('files'), async (req, res) => {
    try {
        const uploadedFiles = req.files.map(file => ({
            originalName: file.originalname,
            savedName: file.filename,
            path: path.relative(WWW_ROOT, file.path),
            size: file.size
        }));
        res.json({ success: true, files: uploadedFiles });
    } catch (error) {
        res.status(500).json({ error: 'Failed to upload files' });
    }
});

// ===== GIT API =====
const simpleGit = require('simple-git');

app.post('/api/git/clone', async (req, res) => {
    try {
        const { url, branch, token, siteName } = req.body;
        if (!url) {
            return res.status(400).json({ error: 'Repository URL is required' });
        }
        const urlPattern = /^(https?:\/\/)?(www\.)?github\.com\/[\w-]+\/[\w-]+/i;
        if (!urlPattern.test(url)) {
            return res.status(400).json({ error: 'Invalid GitHub repository URL' });
        }
        let site = siteName;
        if (!site) {
            const parts = url.split('/');
            const repoName = parts[parts.length - 1];
            site = repoName.replace(/\.git$/, '');
        }
        site = site.replace(/[^a-zA-Z0-9-_]/g, '');
        if (!site) {
            return res.status(400).json({ error: 'Invalid site name' });
        }
        const sitePath = path.join(WWW_ROOT, site);
        const normalizedPath = path.normalize(sitePath);
        if (!normalizedPath.startsWith(WWW_ROOT)) {
            return res.status(403).json({ error: 'Access denied' });
        }
        let cloneUrl = url;
        if (token) {
            const tokenPrefix = `x-access-token:${token}@`;
            const protocolEnd = cloneUrl.indexOf('://') + 3;
            cloneUrl = `${cloneUrl.substring(0, protocolEnd)}${tokenPrefix}${cloneUrl.substring(protocolEnd)}`;
        }
        if (fs.existsSync(normalizedPath)) {
            await fs.remove(normalizedPath);
        }
        const git = simpleGit();
        const branchName = branch || 'main';
        await git.clone(cloneUrl, normalizedPath, ['--branch', branchName, '--single-branch', '--depth', '1']);
        const gitDir = path.join(normalizedPath, '.git');
        if (fs.existsSync(gitDir)) {
            await fs.remove(gitDir);
        }
        res.json({ success: true, site, path: `/sites/${site}`, branch: branchName, message: 'Repository cloned successfully' });
    } catch (error) {
        res.status(500).json({ error: 'Failed to clone repository', details: error.message });
    }
});

app.post('/api/git/pull/:site', async (req, res) => {
    try {
        const { site } = req.params;
        const { branch, token } = req.body;
        const sitePath = path.join(WWW_ROOT, site);
        const normalizedPath = path.normalize(sitePath);
        if (!normalizedPath.startsWith(WWW_ROOT) || !fs.existsSync(normalizedPath)) {
            return res.status(404).json({ error: 'Site not found' });
        }
        const gitDir = path.join(normalizedPath, '.git');
        if (!fs.existsSync(gitDir)) {
            return res.status(400).json({ error: 'Not a git repository' });
        }
        const git = simpleGit(normalizedPath);
        if (token) {
            const originUrl = await git.remote(['get-url', 'origin']);
            const tokenPrefix = `x-access-token:${token}@`;
            const protocolEnd = originUrl.indexOf('://') + 3;
            const authUrl = `${originUrl.substring(0, protocolEnd)}${tokenPrefix}${originUrl.substring(protocolEnd)}`;
            await git.remote(['set-url', 'origin', authUrl]);
        }
        const branchName = branch || 'main';
        await git.fetch();
        await git.pull('origin', branchName);
        res.json({ success: true, site, branch: branchName, message: 'Repository updated successfully' });
    } catch (error) {
        res.status(500).json({ error: 'Failed to pull repository', details: error.message });
    }
});

app.post('/api/git/info', async (req, res) => {
    try {
        const { url } = req.body;
        if (!url) {
            return res.status(400).json({ error: 'Repository URL is required' });
        }
        const urlPattern = /^(https?:\/\/)?(www\.)?github\.com\/([^/]+)\/([^/]+)/i;
        const match = url.match(urlPattern);
        if (!match) {
            return res.status(400).json({ error: 'Invalid GitHub repository URL' });
        }
        const [, , , owner, repo] = match;
        const repoName = repo.replace(/\.git$/, '');
        res.json({ success: true, owner, repo: repoName, defaultBranch: 'main', suggestedSite: repoName });
    } catch (error) {
        res.status(500).json({ error: 'Failed to get repository info' });
    }
});

// ===== TUNNEL API =====
const tunnelRoutes = require('./routes/tunnel');
app.use('/api/tunnel', tunnelRoutes);

// ===== SERVE HOSTED SITES =====
app.use('/sites', (req, res, next) => {
    const requestedPath = req.path;
    const sitePath = path.join(WWW_ROOT, requestedPath);
    const normalizedPath = path.normalize(sitePath);
    if (!normalizedPath.startsWith(WWW_ROOT)) {
        return res.status(403).json({ error: 'Access denied' });
    }
    if (fs.existsSync(normalizedPath) && fs.statSync(normalizedPath).isDirectory()) {
        express.static(normalizedPath)(req, res, next);
    } else {
        next();
    }
});

// ===== CATCH ALL =====
app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// ===== START SERVER =====
app.listen(PORT, () => {
    console.log(`🚀 Termux CPanel running on http://localhost:${PORT}`);
    console.log(`📁 Serving websites from: ${WWW_ROOT}`);
    console.log(`🌐 Access control panel at: http://localhost:${PORT}`);
});

process.on('SIGINT', () => {
    console.log('\n📴 Shutting down server...');
    process.exit(0);
});

module.exports = app;
