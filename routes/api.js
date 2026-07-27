const express = require('express');
const router = express.Router();
const path = require('path');
const fs = require('fs-extra');
const si = require('systeminformation');

// System metrics endpoint
router.get('/system', async (req, res) => {
  try {
    const [cpu, mem, fsSize, uptime, network] = await Promise.all([
      si.cpu(),
      si.mem(),
      si.fsSize(),
      si.time(),
      si.networkInterfaces()
    ]);
    
    // Get CPU usage
    const cpuLoad = await si.currentLoad();
    
    res.json({
      cpu: {
        manufacturer: cpu.manufacturer,
        brand: cpu.brand,
        speed: cpu.speed,
        cores: cpu.cores,
        load: cpuLoad.currentLoad
      },
      memory: {
        total: mem.total,
        used: mem.active,
        free: mem.free,
        usage: ((mem.active / mem.total) * 100).toFixed(1)
      },
      storage: fsSize.map(drive => ({
        name: drive.fs,
        mount: drive.mount,
        size: drive.size,
        used: drive.used,
        available: drive.available,
        usage: ((drive.used / drive.size) * 100).toFixed(1)
      })),
      uptime: {
        system: uptime.uptime,
        node: process.uptime()
      },
      network: network.map(iface => ({
        name: iface.iface,
        ip: iface.ip4,
        mac: iface.mac
      })),
      hostname: require('os').hostname(),
      platform: process.platform,
      nodeVersion: process.version,
      time: new Date().toISOString()
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to get system metrics', details: error.message });
  }
});

// Get all hosted sites
router.get('/sites', async (req, res) => {
  try {
    const wwwDir = path.join(__dirname, '..', 'www');
    const sites = [];
    
    if (fs.existsSync(wwwDir)) {
      const items = await fs.readdir(wwwDir);
      
      for (const item of items) {
        const itemPath = path.join(wwwDir, item);
        const stat = await fs.stat(itemPath);
        
        if (stat.isDirectory()) {
          // Check if it's a git repository
          const gitDir = path.join(itemPath, '.git');
          const isGit = fs.existsSync(gitDir);
          
          // Check for common index files
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
    res.status(500).json({ error: 'Failed to list sites', details: error.message });
  }
});

// Create new site
router.post('/sites/create', async (req, res) => {
  try {
    const { name } = req.body;
    if (!name || !/^[a-zA-Z0-9-_]+$/.test(name)) {
      return res.status(400).json({ error: 'Invalid site name. Use only letters, numbers, hyphens and underscores.' });
    }
    
    const sitePath = path.join(__dirname, '..', 'www', name);
    const normalizedPath = path.normalize(sitePath);
    const wwwRoot = path.join(__dirname, '..', 'www');
    
    if (!normalizedPath.startsWith(wwwRoot)) {
      return res.status(403).json({ error: 'Access denied' });
    }
    
    if (fs.existsSync(normalizedPath)) {
      return res.status(400).json({ error: 'Site already exists' });
    }
    
    await fs.ensureDir(normalizedPath);
    // Create a default index.html
    const defaultHTML = `<!DOCTYPE html>
<html>
<head>
    <title>Welcome to ${name}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #0f172a; color: #e2e8f0; }
        h1 { color: #818cf8; }
        a { color: #a5b4fc; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>🚀 Welcome to ${name}</h1>
    <p>This website was created using <strong>Termux CPanel</strong> on Android.</p>
    <p>Powered by Node.js and Cloudflare Tunnels.</p>
    <hr>
    <small>Site created: ${new Date().toLocaleString()}</small>
</body>
</html>`;
    
    await fs.writeFile(path.join(normalizedPath, 'index.html'), defaultHTML);
    
    res.json({ success: true, site: { name, path: `/sites/${name}` } });
  } catch (error) {
    res.status(500).json({ error: 'Failed to create site', details: error.message });
  }
});

// Delete site
router.delete('/sites/:name', async (req, res) => {
  try {
    const { name } = req.params;
    const sitePath = path.join(__dirname, '..', 'www', name);
    const normalizedPath = path.normalize(sitePath);
    const wwwRoot = path.join(__dirname, '..', 'www');
    
    if (!normalizedPath.startsWith(wwwRoot) || !fs.existsSync(normalizedPath)) {
      return res.status(404).json({ error: 'Site not found' });
    }
    
    await fs.remove(normalizedPath);
    res.json({ success: true });
  } catch (error) {
    res.status(500).json({ error: 'Failed to delete site', details: error.message });
  }
});

module.exports = router;
