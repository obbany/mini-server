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

// Import route modules
const apiRoutes = require('./routes/api');
const gitRoutes = require('./routes/git');
const tunnelRoutes = require('./routes/tunnel');

const app = express();
const PORT = process.env.PORT || 3000;
const WWW_ROOT = path.join(__dirname, 'www');

// Ensure www directory exists
fs.ensureDirSync(WWW_ROOT);

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

// Static file serving for control panel
app.use(express.static(path.join(__dirname, 'public'), {
  maxAge: '1d',
  etag: true
}));

// Serve hosted websites dynamically
app.use('/sites', (req, res, next) => {
  const requestedPath = req.path;
  const sitePath = path.join(WWW_ROOT, requestedPath);
  
  // Security: Ensure path is within www directory
  const normalizedPath = path.normalize(sitePath);
  if (!normalizedPath.startsWith(WWW_ROOT)) {
    return res.status(403).json({ error: 'Access denied' });
  }
  
  // Check if path exists and serve static files
  if (fs.existsSync(normalizedPath) && fs.statSync(normalizedPath).isDirectory()) {
    express.static(normalizedPath)(req, res, next);
  } else {
    next();
  }
});

// API Routes
app.use('/api', apiRoutes);
app.use('/api/git', gitRoutes);
app.use('/api/tunnel', tunnelRoutes);

// System health endpoint
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
      cpu: {
        speed: cpu.avg || 0,
        usage: 0 // Will be calculated on frontend using other metrics
      },
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
      }
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to fetch system health', details: error.message });
  }
});

// File operations endpoints
app.post('/api/files/list', async (req, res) => {
  try {
    const { path: dirPath } = req.body;
    const fullPath = path.join(WWW_ROOT, dirPath || '');
    
    // Security check
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
      } catch (err) {
        return null;
      }
    }));
    
    res.json({
      success: true,
      currentPath: path.relative(WWW_ROOT, normalizedPath) || '/',
      items: items.filter(item => item !== null)
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to list files', details: error.message });
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
    res.status(500).json({ error: 'Failed to create file', details: error.message });
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
    res.status(500).json({ error: 'Failed to rename file', details: error.message });
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
    res.status(500).json({ error: 'Failed to delete files', details: error.message });
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
    
    res.json({
      success: true,
      content: content,
      name: path.basename(normalizedPath),
      path: req.params.path,
      extension: extension,
      size: stat.size,
      modified: stat.mtime
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to read file', details: error.message });
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
    res.status(500).json({ error: 'Failed to write file', details: error.message });
  }
});

// File upload endpoint
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

const upload = multer({ 
  storage: storage,
  limits: { fileSize: 100 * 1024 * 1024 } // 100MB limit
});

app.post('/api/files/upload', upload.array('files'), async (req, res) => {
  try {
    const uploadedFiles = req.files.map(file => ({
      originalName: file.originalname,
      savedName: file.filename,
      path: path.relative(WWW_ROOT, file.path),
      size: file.size
    }));
    
    res.json({ 
      success: true, 
      files: uploadedFiles
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to upload files', details: error.message });
  }
});

// Create zip archive
app.post('/api/files/zip', async (req, res) => {
  try {
    const { path: targetPath, name } = req.body;
    const sourcePath = path.join(WWW_ROOT, targetPath);
    const normalizedSource = path.normalize(sourcePath);
    
    if (!normalizedSource.startsWith(WWW_ROOT)) {
      return res.status(403).json({ error: 'Access denied' });
    }
    
    const zipPath = path.join(WWW_ROOT, `${name}.zip`);
    const output = fs.createWriteStream(zipPath);
    const archive = archiver('zip', { zlib: { level: 9 } });
    
    output.on('close', () => {
      res.json({ 
        success: true, 
        zipPath: path.relative(WWW_ROOT, zipPath),
        size: archive.pointer()
      });
    });
    
    archive.on('error', (err) => {
      throw err;
    });
    
    archive.pipe(output);
    archive.directory(normalizedSource, false);
    archive.finalize();
  } catch (error) {
    res.status(500).json({ error: 'Failed to create zip', details: error.message });
  }
});

// Serve the control panel
app.get('*', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Start server
app.listen(PORT, () => {
  console.log(`🚀 Termux CPanel running on http://localhost:${PORT}`);
  console.log(`📁 Serving websites from: ${WWW_ROOT}`);
  console.log(`🌐 Access control panel at: http://localhost:${PORT}`);
});

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\n📴 Shutting down server...');
  process.exit(0);
});

module.exports = app;
