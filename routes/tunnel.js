const express = require('express');
const router = express.Router();
const { spawn, exec } = require('child_process');
const fs = require('fs-extra');
const path = require('path');
const os = require('os');

const tunnels = {};

router.get('/status', (req, res) => {
    exec('cloudflared --version', (error, stdout) => {
        if (error) {
            return res.json({ installed: false, message: 'cloudflared not installed' });
        }
        res.json({ installed: true, version: stdout.trim(), running: Object.keys(tunnels).length > 0 });
    });
});

router.post('/start-quick', (req, res) => {
    try {
        const { port = 3000 } = req.body;
        if (tunnels['quick']) {
            return res.status(400).json({ error: 'Quick tunnel already running' });
        }
        const tunnelProcess = spawn('cloudflared', ['tunnel', '--url', `http://localhost:${port}`], {
            stdio: ['ignore', 'pipe', 'pipe']
        });
        let url = null;
        tunnelProcess.stdout.on('data', (data) => {
            const output = data.toString();
            const urlMatch = output.match(/https:\/\/[a-zA-Z0-9-]+\.trycloudflare\.com/);
            if (urlMatch && !url) {
                url = urlMatch[0];
                res.json({ success: true, url: url, port: port, type: 'quick' });
            }
        });
        tunnelProcess.stderr.on('data', (data) => {
            console.error('Tunnel error:', data.toString());
        });
        tunnelProcess.on('error', (error) => {
            console.error('Tunnel process error:', error);
            if (!res.headersSent) {
                res.status(500).json({ error: 'Failed to start tunnel', details: error.message });
            }
        });
        tunnelProcess.on('exit', (code) => {
            console.log(`Tunnel process exited with code ${code}`);
            delete tunnels['quick'];
        });
        tunnels['quick'] = tunnelProcess;
    } catch (error) {
        res.status(500).json({ error: 'Failed to start tunnel', details: error.message });
    }
});

router.post('/start-named', async (req, res) => {
    try {
        const { tunnelName, configPath, port = 3000 } = req.body;
        if (!tunnelName) {
            return res.status(400).json({ error: 'Tunnel name is required' });
        }
        if (tunnels[tunnelName]) {
            return res.status(400).json({ error: 'Tunnel already running' });
        }
        const configDir = path.join(os.homedir(), '.cloudflared');
        await fs.ensureDir(configDir);
        let configFile = configPath;
        if (!configFile) {
            configFile = path.join(configDir, 'config.yml');
            const configContent = `
tunnel: ${tunnelName}
credentials-file: ${path.join(configDir, `${tunnelName}.json`)}
ingress:
  - hostname: *.${tunnelName}.com
    service: http://localhost:${port}
  - service: http_status:404
`;
            await fs.writeFile(configFile, configContent);
        }
        const tunnelProcess = spawn('cloudflared', ['tunnel', '--config', configFile, 'run'], {
            stdio: ['ignore', 'pipe', 'pipe']
        });
        tunnelProcess.stdout.on('data', (data) => {
            console.log(`Tunnel ${tunnelName} output:`, data.toString());
        });
        tunnelProcess.stderr.on('data', (data) => {
            console.error(`Tunnel ${tunnelName} error:`, data.toString());
        });
        tunnelProcess.on('error', (error) => {
            console.error(`Tunnel ${tunnelName} process error:`, error);
        });
        tunnelProcess.on('exit', (code) => {
            console.log(`Tunnel ${tunnelName} exited with code ${code}`);
            delete tunnels[tunnelName];
        });
        tunnels[tunnelName] = tunnelProcess;
        res.json({ success: true, tunnelName: tunnelName, port: port, type: 'named', configFile: configFile, message: 'Named tunnel started successfully' });
    } catch (error) {
        res.status(500).json({ error: 'Failed to start named tunnel', details: error.message });
    }
});

router.post('/login', (req, res) => {
    const loginProcess = spawn('cloudflared', ['tunnel', 'login'], { stdio: 'inherit' });
    loginProcess.on('exit', (code) => {
        if (code === 0) {
            res.json({ success: true, message: 'Login successful' });
        } else {
            res.status(500).json({ error: 'Login failed', code });
        }
    });
});

router.post('/create-named', async (req, res) => {
    try {
        const { tunnelName } = req.body;
        if (!tunnelName) {
            return res.status(400).json({ error: 'Tunnel name is required' });
        }
        const createProcess = spawn('cloudflared', ['tunnel', 'create', tunnelName], {
            stdio: ['ignore', 'pipe', 'pipe']
        });
        let output = '', error = '';
        createProcess.stdout.on('data', (data) => { output += data.toString(); });
        createProcess.stderr.on('data', (data) => { error += data.toString(); });
        createProcess.on('exit', (code) => {
            if (code === 0) {
                res.json({ success: true, tunnelName: tunnelName, output: output, message: 'Tunnel created successfully' });
            } else {
                res.status(500).json({ error: 'Failed to create tunnel', details: error });
            }
        });
    } catch (error) {
        res.status(500).json({ error: 'Failed to create named tunnel', details: error.message });
    }
});

router.post('/route-dns', (req, res) => {
    try {
        const { tunnelName, domain } = req.body;
        if (!tunnelName || !domain) {
            return res.status(400).json({ error: 'Tunnel name and domain are required' });
        }
        const routeProcess = spawn('cloudflared', ['tunnel', 'route', 'dns', tunnelName, domain], {
            stdio: ['ignore', 'pipe', 'pipe']
        });
        let output = '', error = '';
        routeProcess.stdout.on('data', (data) => { output += data.toString(); });
        routeProcess.stderr.on('data', (data) => { error += data.toString(); });
        routeProcess.on('exit', (code) => {
            if (code === 0) {
                res.json({ success: true, tunnelName: tunnelName, domain: domain, output: output, message: 'DNS route configured successfully' });
            } else {
                res.status(500).json({ error: 'Failed to route DNS', details: error });
            }
        });
    } catch (error) {
        res.status(500).json({ error: 'Failed to route DNS', details: error.message });
    }
});

router.post('/stop/:name', (req, res) => {
    try {
        const { name } = req.params;
        if (!tunnels[name]) {
            return res.status(404).json({ error: 'Tunnel not found' });
        }
        tunnels[name].kill('SIGTERM');
        delete tunnels[name];
        res.json({ success: true, message: `Tunnel ${name} stopped` });
    } catch (error) {
        res.status(500).json({ error: 'Failed to stop tunnel', details: error.message });
    }
});

router.get('/status/:name', (req, res) => {
    const { name } = req.params;
    if (!tunnels[name]) {
        return res.json({ running: false, name: name });
    }
    res.json({ running: true, name: name, pid: tunnels[name].pid });
});

router.get('/list', (req, res) => {
    const runningTunnels = Object.keys(tunnels).map(name => ({
        name: name,
        pid: tunnels[name].pid
    }));
    res.json({ running: runningTunnels });
});

router.get('/list-named', (req, res) => {
    exec('cloudflared tunnel list', (error, stdout) => {
        if (error) {
            return res.status(500).json({ error: 'Failed to list tunnels', details: error.message });
        }
        const lines = stdout.split('\n').slice(1);
        const tunnels = lines.filter(line => line.trim()).map(line => {
            const parts = line.trim().split(/\s+/);
            return { name: parts[0] || '', id: parts[1] || '', status: parts[2] || '' };
        });
        res.json({ success: true, tunnels });
    });
});

router.delete('/delete-named/:name', (req, res) => {
    try {
        const { name } = req.params;
        if (tunnels[name]) {
            tunnels[name].kill('SIGTERM');
            delete tunnels[name];
        }
        exec(`cloudflared tunnel delete ${name}`, (error, stdout, stderr) => {
            if (error) {
                return res.status(500).json({ error: 'Failed to delete tunnel', details: stderr });
            }
            res.json({ success: true, message: `Tunnel ${name} deleted` });
        });
    } catch (error) {
        res.status(500).json({ error: 'Failed to delete named tunnel', details: error.message });
    }
});

module.exports = router;
