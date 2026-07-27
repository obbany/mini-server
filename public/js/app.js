// ============================================
// TERMUX CPANEL - MAIN APPLICATION
// ============================================

class TermuxCPanel {
    constructor() {
        this.currentPage = 'dashboard';
        this.systemInterval = null;
        this.init();
    }
    
    init() {
        this.setupEventListeners();
        this.loadDashboard();
        this.startSystemMonitoring();
        this.setupFileUpload();
        this.loadSites();
        this.loadDomains();
        this.checkForUpdates();
    }
    
    setupEventListeners() {
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', (e) => {
                const page = link.dataset.page;
                this.switchPage(page);
            });
        });
    }
    
    switchPage(page) {
        this.currentPage = page;
        
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.classList.toggle('active', link.dataset.page === page);
        });
        
        document.querySelectorAll('.page-content-inner').forEach(content => {
            content.classList.add('hidden');
        });
        
        const targetPage = document.getElementById(`page-${page}`);
        if (targetPage) {
            targetPage.classList.remove('hidden');
        }
        
        const titles = {
            dashboard: 'Dashboard',
            files: 'File Manager',
            git: 'GitHub Deployer',
            domains: 'Domain Manager',
            tunnel: 'Cloudflare Tunnel',
            sites: 'Hosted Sites'
        };
        document.getElementById('page-title').textContent = titles[page] || page;
        
        if (page === 'dashboard') this.loadDashboard();
        if (page === 'sites') this.loadSites();
        if (page === 'files') fileManager.loadFiles();
        if (page === 'git') gitDeploy.loadDeployedSites();
        if (page === 'domains') this.loadDomains();
        
        if (window.innerWidth < 1024) {
            this.closeSidebar();
        }
    }
    
    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    }
    
    closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebar-overlay').classList.remove('open');
    }
    
    async loadDashboard() {
        try {
            const response = await fetch('/api/health');
            const data = await response.json();
            if (data.status === 'online') {
                this.updateDashboardMetrics(data);
                this.updateSidebarMetrics(data);
            }
        } catch (error) {
            console.error('Failed to load dashboard:', error);
        }
        
        try {
            const sitesResponse = await fetch('/api/sites');
            const sitesData = await sitesResponse.json();
            if (sitesData.success) {
                this.renderDashboardSites(sitesData.sites);
            }
        } catch (error) {
            console.error('Failed to load sites:', error);
        }
    }
    
    updateDashboardMetrics(data) {
        const cpu = data.cpu?.usage || 0;
        const ram = data.memory?.usagePercentage || 0;
        const storage = data.storage?.usagePercentage || 0;
        const uptime = data.uptime?.node || 0;
        
        document.getElementById('dashboard-cpu').textContent = `${cpu}%`;
        document.getElementById('dashboard-cpu-bar').style.width = `${Math.min(cpu, 100)}%`;
        
        document.getElementById('dashboard-ram').textContent = `${ram}%`;
        document.getElementById('dashboard-ram-bar').style.width = `${Math.min(ram, 100)}%`;
        
        document.getElementById('dashboard-storage').textContent = `${storage}%`;
        document.getElementById('dashboard-storage-bar').style.width = `${Math.min(storage, 100)}%`;
        
        document.getElementById('dashboard-uptime').textContent = this.formatUptime(uptime);
    }
    
    updateSidebarMetrics(data) {
        const cpu = data.cpu?.usage || 0;
        const ram = data.memory?.usagePercentage || 0;
        const uptime = data.uptime?.node || 0;
        
        document.getElementById('sidebar-cpu').textContent = `${cpu}%`;
        document.getElementById('sidebar-ram').textContent = `${ram}%`;
        document.getElementById('sidebar-uptime').textContent = this.formatUptime(uptime);
    }
    
    formatUptime(seconds) {
        if (seconds < 60) return `${Math.floor(seconds)}s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
        return `${Math.floor(seconds / 86400)}d`;
    }
    
    renderDashboardSites(sites) {
        const container = document.getElementById('dashboard-sites-list');
        if (!sites || sites.length === 0) {
            container.innerHTML = '<p class="text-sm text-slate-400">No sites hosted yet</p>';
            return;
        }
        
        container.innerHTML = sites.slice(0, 5).map(site => `
            <div class="flex items-center justify-between p-3 glass-light rounded-lg hover:border-indigo-500/20 transition-all">
                <div class="flex items-center gap-3">
                    <i class="fas fa-folder ${site.hasIndex ? 'text-indigo-400' : 'text-slate-500'}"></i>
                    <div>
                        <div class="font-medium">${site.name}</div>
                        <div class="text-xs text-slate-400">${site.isGit ? 'Git' : 'Static'}</div>
                    </div>
                </div>
                <a href="${site.path}" target="_blank" class="text-sm text-indigo-400 hover:text-indigo-300 transition-colors">
                    <i class="fas fa-external-link-alt"></i>
                </a>
            </div>
        `).join('');
    }
    
    startSystemMonitoring() {
        if (this.systemInterval) clearInterval(this.systemInterval);
        this.systemInterval = setInterval(() => {
            this.loadDashboard();
        }, 5000);
    }
    
    setupFileUpload() {
        const uploadInput = document.getElementById('file-upload-input');
        if (uploadInput) {
            uploadInput.addEventListener('change', (e) => {
                fileManager.handleUpload(e);
            });
        }
    }
    
    async loadSites() {
        try {
            const response = await fetch('/api/sites');
            const data = await response.json();
            if (data.success) {
                siteManager.renderSites(data.sites);
                this.updateSiteSelect(data.sites);
            }
        } catch (error) {
            console.error('Failed to load sites:', error);
        }
    }
    
    updateSiteSelect(sites) {
        const select = document.getElementById('domain-site');
        if (!select) return;
        select.innerHTML = '<option value="">Select a site...</option>';
        if (sites) {
            sites.forEach(site => {
                select.innerHTML += `<option value="${site.name}">${site.name}</option>`;
            });
        }
    }
    
    async loadDomains() {
        try {
            const response = await fetch('/api/domains');
            const data = await response.json();
            if (data.success) {
                this.renderDomains(data.domains);
            }
        } catch (error) {
            console.error('Failed to load domains:', error);
        }
    }
    
    renderDomains(domains) {
        const container = document.getElementById('domain-list');
        if (!domains || domains.length === 0) {
            container.innerHTML = '<p class="text-sm text-slate-400">No domains added yet</p>';
            return;
        }
        
        container.innerHTML = domains.map(domain => `
            <div class="flex items-center justify-between p-3 glass-light rounded-lg">
                <div>
                    <div class="font-medium text-indigo-400">${domain.domain}</div>
                    <div class="text-xs text-slate-400">→ ${domain.site || 'No site'}</div>
                </div>
                <div class="flex gap-2">
                    <span class="text-xs px-2 py-1 rounded-full ${domain.status === 'active' ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'}">
                        ${domain.status || 'pending'}
                    </span>
                    <button onclick="domainManager.removeDomain('${domain.id}')" class="text-red-400 hover:text-red-300">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    async checkForUpdates() {
        try {
            const response = await fetch('/api/update/check');
            const data = await response.json();
            if (data.hasUpdate) {
                document.getElementById('update-badge').classList.remove('hidden');
            }
        } catch (error) {
            console.error('Failed to check updates:', error);
        }
    }
    
    refreshPage() {
        this.loadDashboard();
        if (this.currentPage === 'files') fileManager.loadFiles();
        if (this.currentPage === 'sites') this.loadSites();
        if (this.currentPage === 'git') gitDeploy.loadDeployedSites();
        if (this.currentPage === 'domains') this.loadDomains();
        showToast('Refreshed', 'success');
    }
    
    showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// ============================================
// FILE MANAGER
// ============================================
class FileManager {
    constructor() {
        this.currentPath = '';
        this.selectedFile = null;
        this.editor = null;
        this.init();
    }
    
    init() {
        this.setupEditor();
    }
    
    setupEditor() {
        require.config({
            paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs' }
        });
        
        require(['vs/editor/editor.main'], () => {
            this.editor = monaco.editor.create(document.getElementById('monaco-container'), {
                value: '// Select a file to edit',
                language: 'plaintext',
                theme: 'vs-dark',
                automaticLayout: true,
                fontSize: 14,
                minimap: { enabled: false },
                scrollbar: { vertical: 'auto', horizontal: 'auto' },
                bracketPairColorization: { enabled: true },
                renderWhitespace: 'selection',
                tabSize: 2
            });
            
            this.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
                this.saveFile();
            });
        });
    }
    
    async loadFiles(path = '') {
        this.currentPath = path;
        try {
            const response = await fetch('/api/files/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: this.currentPath })
            });
            const data = await response.json();
            if (data.success) {
                this.renderFiles(data.items);
                this.renderBreadcrumb(data.currentPath);
                document.getElementById('file-count').textContent = `${data.items.length} items`;
            }
        } catch (error) {
            console.error('Failed to load files:', error);
            showToast('Failed to load files', 'error');
        }
    }
    
    renderFiles(items) {
        const container = document.getElementById('file-list');
        if (!items || items.length === 0) {
            container.innerHTML = '<p class="text-sm text-slate-400">Empty directory</p>';
            return;
        }
        
        const sorted = items.sort((a, b) => {
            if (a.isDirectory && !b.isDirectory) return -1;
            if (!a.isDirectory && b.isDirectory) return 1;
            return a.name.localeCompare(b.name);
        });
        
        container.innerHTML = sorted.map(item => `
            <div class="file-item ${item.isDirectory ? 'directory' : 'file'}" 
                 data-path="${item.path}" 
                 onclick="fileManager.selectFile('${item.path}', ${item.isDirectory})"
                 ondblclick="fileManager.openFile('${item.path}', ${item.isDirectory})">
                <i class="${item.isDirectory ? 'fas fa-folder text-indigo-400' : 'fas fa-file text-slate-400'} file-icon"></i>
                <span class="file-name">${item.name}</span>
                <span class="file-meta">${item.isDirectory ? '' : this.formatSize(item.size)}</span>
                <span class="file-meta">${this.formatDate(item.modified)}</span>
                <div class="file-actions">
                    ${!item.isDirectory ? `<button onclick="event.stopPropagation(); fileManager.editFile('${item.path}')">Edit</button>` : ''}
                    <button onclick="event.stopPropagation(); fileManager.renameFile('${item.path}')">Rename</button>
                    <button onclick="event.stopPropagation(); fileManager.deleteFile('${item.path}')">Delete</button>
                </div>
            </div>
        `).join('');
    }
    
    renderBreadcrumb(currentPath) {
        const container = document.getElementById('breadcrumb');
        const parts = currentPath ? currentPath.split('/').filter(p => p) : [];
        let html = '<span class="text-slate-400">/</span>';
        let path = '';
        parts.forEach((part, index) => {
            path += '/' + part;
            const isLast = index === parts.length - 1;
            html += `<span class="text-slate-400">/</span>`;
            html += `<span class="${isLast ? 'current' : 'text-slate-300 hover:text-white cursor-pointer'}" 
                           onclick="${isLast ? '' : `fileManager.loadFiles('${path}')`}">${part}</span>`;
        });
        container.innerHTML = html;
    }
    
    selectFile(path, isDirectory) {
        if (isDirectory) {
            this.loadFiles(path);
        } else {
            this.selectedFile = path;
            document.querySelectorAll('.file-item').forEach(el => {
                el.classList.toggle('selected', el.dataset.path === path);
            });
        }
    }
    
    async openFile(path, isDirectory) {
        if (isDirectory) {
            this.loadFiles(path);
        } else {
            this.editFile(path);
        }
    }
    
    async editFile(path) {
        try {
            const response = await fetch(`/api/files/read/${encodeURIComponent(path)}`);
            const data = await response.json();
            if (data.success) {
                this.selectedFile = path;
                document.getElementById('editor-file-path').textContent = path;
                const ext = path.split('.').pop().toLowerCase();
                const languages = {
                    'js': 'javascript', 'ts': 'typescript', 'html': 'html',
                    'css': 'css', 'json': 'json', 'py': 'python',
                    'md': 'markdown', 'php': 'php', 'xml': 'xml',
                    'yml': 'yaml', 'yaml': 'yaml', 'txt': 'plaintext',
                    'sh': 'shell'
                };
                monaco.editor.setModelLanguage(this.editor.getModel(), languages[ext] || 'plaintext');
                this.editor.setValue(data.content);
                document.querySelectorAll('.file-item').forEach(el => {
                    el.classList.toggle('selected', el.dataset.path === path);
                });
            }
        } catch (error) {
            console.error('Failed to read file:', error);
            showToast('Failed to read file', 'error');
        }
    }
    
    async saveFile() {
        if (!this.selectedFile) {
            showToast('No file selected', 'error');
            return;
        }
        const content = this.editor.getValue();
        try {
            const response = await fetch('/api/files/write', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: this.selectedFile, content })
            });
            const data = await response.json();
            if (data.success) {
                showToast('File saved successfully', 'success');
                this.loadFiles(this.currentPath);
            } else {
                showToast('Failed to save file', 'error');
            }
        } catch (error) {
            console.error('Failed to save file:', error);
            showToast('Failed to save file', 'error');
        }
    }
    
    async createFolder() {
        const name = prompt('Enter folder name:');
        if (!name) return;
        try {
            const response = await fetch('/api/files/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: this.currentPath, name, type: 'directory' })
            });
            const data = await response.json();
            if (data.success) {
                showToast('Folder created', 'success');
                this.loadFiles(this.currentPath);
            } else {
                showToast('Failed to create folder', 'error');
            }
        } catch (error) {
            console.error('Failed to create folder:', error);
            showToast('Failed to create folder', 'error');
        }
    }
    
    async createFile() {
        const name = prompt('Enter file name:');
        if (!name) return;
        try {
            const response = await fetch('/api/files/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: this.currentPath, name, type: 'file' })
            });
            const data = await response.json();
            if (data.success) {
                showToast('File created', 'success');
                this.loadFiles(this.currentPath);
                this.editFile(data.path);
            } else {
                showToast('Failed to create file', 'error');
            }
        } catch (error) {
            console.error('Failed to create file:', error);
            showToast('Failed to create file', 'error');
        }
    }
    
    async renameFile(path) {
        const currentName = path.split('/').pop();
        const newName = prompt('Enter new name:', currentName);
        if (!newName || newName === currentName) return;
        try {
            const response = await fetch('/api/files/rename', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ oldPath: path, newName })
            });
            const data = await response.json();
            if (data.success) {
                showToast('File renamed', 'success');
                this.loadFiles(this.currentPath);
            } else {
                showToast('Failed to rename file', 'error');
            }
        } catch (error) {
            console.error('Failed to rename file:', error);
            showToast('Failed to rename file', 'error');
        }
    }
    
    async deleteFile(path) {
        if (!confirm(`Are you sure you want to delete "${path}"?`)) return;
        try {
            const response = await fetch('/api/files/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ paths: [path] })
            });
            const data = await response.json();
            if (data.success) {
                showToast('File deleted', 'success');
                this.loadFiles(this.currentPath);
                if (this.selectedFile === path) {
                    this.selectedFile = null;
                    document.getElementById('editor-file-path').textContent = 'No file selected';
                    this.editor.setValue('// Select a file to edit');
                }
            } else {
                showToast('Failed to delete file', 'error');
            }
        } catch (error) {
            console.error('Failed to delete file:', error);
            showToast('Failed to delete file', 'error');
        }
    }
    
    async handleUpload(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;
        const formData = new FormData();
        formData.append('path', this.currentPath);
        for (const file of files) {
            formData.append('files', file);
        }
        try {
            const response = await fetch('/api/files/upload', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                showToast(`${data.files.length} files uploaded`, 'success');
                this.loadFiles(this.currentPath);
            } else {
                showToast('Failed to upload files', 'error');
            }
        } catch (error) {
            console.error('Failed to upload files:', error);
            showToast('Failed to upload files', 'error');
        }
        event.target.value = '';
    }
    
    refresh() {
        this.loadFiles(this.currentPath);
    }
    
    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    formatDate(date) {
        if (!date) return '';
        const d = new Date(date);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
    }
}

// ============================================
// GITHUB DEPLOYER
// ============================================
class GitDeployer {
    constructor() {
        this.sites = [];
    }
    
    async deploy(event) {
        event.preventDefault();
        const url = document.getElementById('git-url').value;
        const branch = document.getElementById('git-branch').value || 'main';
        const siteName = document.getElementById('git-site-name').value;
        const token = document.getElementById('git-token').value;
        if (!url) {
            showToast('Repository URL is required', 'error');
            return;
        }
        const statusDiv = document.getElementById('git-status');
        const statusContent = document.getElementById('git-status-content');
        statusDiv.classList.remove('hidden');
        statusContent.innerHTML = '<p class="text-sm text-indigo-400">Cloning repository...</p>';
        try {
            const response = await fetch('/api/git/clone', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url, branch, token, siteName })
            });
            const data = await response.json();
            if (data.success) {
                statusContent.innerHTML = `
                    <p class="text-sm text-green-400">✅ ${data.message}</p>
                    <p class="text-sm text-slate-300 mt-2">Site: <strong>${data.site}</strong></p>
                    <p class="text-sm text-slate-300">Path: <strong>${data.path}</strong></p>
                `;
                showToast('Repository deployed successfully', 'success');
                this.loadDeployedSites();
                app.loadSites();
            } else {
                statusContent.innerHTML = `<p class="text-sm text-red-400">❌ ${data.error || 'Failed to deploy'}</p>`;
                showToast('Failed to deploy', 'error');
            }
        } catch (error) {
            statusContent.innerHTML = `<p class="text-sm text-red-400">❌ ${error.message}</p>`;
            showToast('Failed to deploy', 'error');
        }
    }
    
    async loadInfo() {
        const url = document.getElementById('git-url').value;
        if (!url) {
            showToast('Please enter a repository URL', 'error');
            return;
        }
        try {
            const response = await fetch('/api/git/info', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url })
            });
            const data = await response.json();
            if (data.success) {
                const statusDiv = document.getElementById('git-status');
                const statusContent = document.getElementById('git-status-content');
                statusDiv.classList.remove('hidden');
                statusContent.innerHTML = `
                    <p class="text-sm text-slate-300">Owner: <strong>${data.owner}</strong></p>
                    <p class="text-sm text-slate-300">Repository: <strong>${data.repo}</strong></p>
                    <p class="text-sm text-slate-300">Default Branch: <strong>${data.defaultBranch}</strong></p>
                    <p class="text-sm text-slate-300">Suggested Site: <strong>${data.suggestedSite}</strong></p>
                `;
                if (!document.getElementById('git-site-name').value) {
                    document.getElementById('git-site-name').value = data.suggestedSite;
                }
            } else {
                showToast('Failed to get repository info', 'error');
            }
        } catch (error) {
            console.error('Failed to get repo info:', error);
            showToast('Failed to get repository info', 'error');
        }
    }
    
    async loadDeployedSites() {
        try {
            const response = await fetch('/api/sites');
            const data = await response.json();
            const container = document.getElementById('deployed-sites');
            if (data.success && data.sites && data.sites.length > 0) {
                container.innerHTML = data.sites.map(site => `
                    <div class="flex items-center justify-between p-3 glass-light rounded-lg">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-folder ${site.isGit ? 'text-indigo-400' : 'text-slate-500'}"></i>
                            <div>
                                <div class="font-medium">${site.name}</div>
                                <div class="text-xs text-slate-400">${site.isGit ? 'Git' : 'Static'} • ${fileManager.formatSize(site.size)}</div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <a href="${site.path}" target="_blank" class="text-sm text-indigo-400 hover:text-indigo-300">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            ${site.isGit ? `<button onclick="gitDeploy.pullSite('${site.name}')" class="text-sm text-slate-400 hover:text-white">
                                <i class="fas fa-sync-alt"></i>
                            </button>` : ''}
                            <button onclick="siteManager.deleteSite('${site.name}')" class="text-sm text-red-400 hover:text-red-300">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-sm text-slate-400">No sites deployed yet</p>';
            }
        } catch (error) {
            console.error('Failed to load deployed sites:', error);
        }
    }
    
    async pullSite(siteName) {
        if (!confirm(`Pull latest changes for "${siteName}"?`)) return;
        const token = document.getElementById('git-token').value;
        try {
            const response = await fetch(`/api/git/pull/${siteName}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token })
            });
            const data = await response.json();
            if (data.success) {
                showToast('Site updated successfully', 'success');
                this.loadDeployedSites();
            } else {
                showToast('Failed to update site', 'error');
            }
        } catch (error) {
            console.error('Failed to pull:', error);
            showToast('Failed to update site', 'error');
        }
    }
}

// ============================================
// TUNNEL MANAGER
// ============================================
class TunnelManager {
    constructor() {
        this.quickTunnelUrl = null;
        this.checkStatus();
    }
    
    async checkStatus() {
        try {
            const response = await fetch('/api/tunnel/status');
            const data = await response.json();
            if (data.installed) {
                document.getElementById('quick-tunnel-status').innerHTML = `
                    <span class="text-green-400">✅ cloudflared installed</span>
                    <span class="text-xs text-slate-400 block">${data.version}</span>
                `;
            } else {
                document.getElementById('quick-tunnel-status').innerHTML = `
                    <span class="text-red-400">❌ cloudflared not installed</span>
                    <span class="text-xs text-slate-400 block">Run: pkg install cloudflared</span>
                `;
            }
        } catch (error) {
            console.error('Failed to check tunnel status:', error);
        }
    }
    
    async startQuick() {
        try {
            const response = await fetch('/api/tunnel/start-quick', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ port: 3000 })
            });
            const data = await response.json();
            if (data.success) {
                this.quickTunnelUrl = data.url;
                document.getElementById('quick-tunnel-status').innerHTML = `
                    <span class="text-green-400">✅ Tunnel running</span>
                    <a href="${data.url}" target="_blank" class="text-indigo-400 hover:text-indigo-300 block mt-1">
                        ${data.url}
                    </a>
                `;
                showToast(`Tunnel started: ${data.url}`, 'success');
            } else {
                showToast('Failed to start tunnel', 'error');
            }
        } catch (error) {
            console.error('Failed to start tunnel:', error);
            showToast('Failed to start tunnel', 'error');
        }
    }
    
    async stop(name) {
        try {
            const response = await fetch(`/api/tunnel/stop/${name}`, { method: 'POST' });
            const data = await response.json();
            if (data.success) {
                if (name === 'quick') {
                    this.quickTunnelUrl = null;
                    document.getElementById('quick-tunnel-status').innerHTML = `
                        <span class="text-slate-400">⏹️ Stopped</span>
                    `;
                } else {
                    document.getElementById('named-tunnel-status').innerHTML = `
                        <span class="text-slate-400">⏹️ Stopped</span>
                    `;
                }
                showToast('Tunnel stopped', 'success');
            } else {
                showToast('Failed to stop tunnel', 'error');
            }
        } catch (error) {
            console.error('Failed to stop tunnel:', error);
            showToast('Failed to stop tunnel', 'error');
        }
    }
    
    async startNamed(event) {
        event.preventDefault();
        const name = document.getElementById('tunnel-name').value;
        if (!name) {
            showToast('Tunnel name is required', 'error');
            return;
        }
        try {
            const response = await fetch('/api/tunnel/start-named', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tunnelName: name, port: 3000 })
            });
            const data = await response.json();
            if (data.success) {
                document.getElementById('named-tunnel-status').innerHTML = `
                    <span class="text-green-400">✅ Named tunnel running</span>
                    <span class="text-xs text-slate-400 block">Name: ${data.tunnelName}</span>
                `;
                showToast(`Named tunnel "${name}" started`, 'success');
            } else {
                showToast('Failed to start named tunnel', 'error');
            }
        } catch (error) {
            console.error('Failed to start named tunnel:', error);
            showToast('Failed to start named tunnel', 'error');
        }
    }
    
    async createNamed() {
        const name = document.getElementById('tunnel-name').value;
        if (!name) {
            showToast('Tunnel name is required', 'error');
            return;
        }
        try {
            const response = await fetch('/api/tunnel/create-named', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tunnelName: name })
            });
            const data = await response.json();
            if (data.success) {
                showToast(`Tunnel "${name}" created`, 'success');
            } else {
                showToast('Failed to create tunnel', 'error');
            }
        } catch (error) {
            console.error('Failed to create tunnel:', error);
            showToast('Failed to create tunnel', 'error');
        }
    }
    
    async routeDNS() {
        const name = document.getElementById('tunnel-name').value;
        const domain = document.getElementById('tunnel-domain').value;
        if (!name || !domain) {
            showToast('Tunnel name and domain are required', 'error');
            return;
        }
        try {
            const response = await fetch('/api/tunnel/route-dns', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tunnelName: name, domain })
            });
            const data = await response.json();
            if (data.success) {
                showToast(`DNS route configured for ${domain}`, 'success');
            } else {
                showToast('Failed to configure DNS route', 'error');
            }
        } catch (error) {
            console.error('Failed to route DNS:', error);
            showToast('Failed to configure DNS route', 'error');
        }
    }
}

// ============================================
// SITE MANAGER
// ============================================
class SiteManager {
    constructor() {
        this.sites = [];
    }
    
    async createSite() {
        const name = prompt('Enter site name (letters, numbers, hyphens, underscores only):');
        if (!name) return;
        if (!/^[a-zA-Z0-9-_]+$/.test(name)) {
            showToast('Invalid site name. Use only letters, numbers, hyphens and underscores.', 'error');
            return;
        }
        try {
            const response = await fetch('/api/sites/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name })
            });
            const data = await response.json();
            if (data.success) {
                showToast(`Site "${name}" created`, 'success');
                this.loadSites();
                app.loadDashboard();
            } else {
                showToast(data.error || 'Failed to create site', 'error');
            }
        } catch (error) {
            console.error('Failed to create site:', error);
            showToast('Failed to create site', 'error');
        }
    }
    
    async deleteSite(name) {
        if (!confirm(`Are you sure you want to delete "${name}"?`)) return;
        try {
            const response = await fetch(`/api/sites/${encodeURIComponent(name)}`, { method: 'DELETE' });
            const data = await response.json();
            if (data.success) {
                showToast(`Site "${name}" deleted`, 'success');
                this.loadSites();
                app.loadDashboard();
            } else {
                showToast('Failed to delete site', 'error');
            }
        } catch (error) {
            console.error('Failed to delete site:', error);
            showToast('Failed to delete site', 'error');
        }
    }
    
    renderSites(sites) {
        this.sites = sites || [];
        const container = document.getElementById('sites-list');
        if (!this.sites || this.sites.length === 0) {
            container.innerHTML = '<p class="text-sm text-slate-400 col-span-full">No sites hosted yet</p>';
            return;
        }
        container.innerHTML = this.sites.map(site => `
            <div class="site-card p-4">
                <div class="flex items-start justify-between">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-folder text-2xl ${site.isGit ? 'text-indigo-400' : 'text-slate-500'}"></i>
                        <div>
                            <h4 class="font-semibold">${site.name}</h4>
                            <div class="text-xs text-slate-400">${site.isGit ? 'Git Repository' : 'Static Site'}</div>
                            <div class="text-xs text-slate-500">${fileManager.formatSize(site.size)}</div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="${site.path}" target="_blank" class="text-indigo-400 hover:text-indigo-300 text-sm">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button onclick="siteManager.deleteSite('${site.name}')" class="text-red-400 hover:text-red-300 text-sm">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="${site.path}" target="_blank" class="text-xs text-indigo-400 hover:text-indigo-300 flex items-center gap-1">
                        <i class="fas fa-globe"></i> View Site
                    </a>
                    <button onclick="window.location.href='/?path=${site.name}'" class="text-xs text-slate-400 hover:text-white flex items-center gap-1">
                        <i class="fas fa-folder-open"></i> Manage Files
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    async loadSites() {
        try {
            const response = await fetch('/api/sites');
            const data = await response.json();
            if (data.success) {
                this.renderSites(data.sites);
            }
        } catch (error) {
            console.error('Failed to load sites:', error);
        }
    }
}

// ============================================
// DOMAIN MANAGER (নতুন)
// ============================================
class DomainManager {
    constructor() {
        this.domains = [];
    }
    
    async addDomain(event) {
        event.preventDefault();
        const domain = document.getElementById('domain-name').value;
        const site = document.getElementById('domain-site').value;
        const tunnel = document.getElementById('domain-tunnel').value;
        
        if (!domain) {
            showToast('Domain name is required', 'error');
            return;
        }
        
        try {
            const response = await fetch('/api/domains/add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ domain, site, tunnel })
            });
            const data = await response.json();
            if (data.success) {
                showToast(`Domain "${domain}" added successfully`, 'success');
                app.loadDomains();
                document.getElementById('domain-form').reset();
            } else {
                showToast(data.error || 'Failed to add domain', 'error');
            }
        } catch (error) {
            console.error('Failed to add domain:', error);
            showToast('Failed to add domain', 'error');
        }
    }
    
    async removeDomain(id) {
        if (!confirm('Remove this domain?')) return;
        try {
            const response = await fetch(`/api/domains/remove/${id}`, { method: 'DELETE' });
            const data = await response.json();
            if (data.success) {
                showToast('Domain removed', 'success');
                app.loadDomains();
            } else {
                showToast('Failed to remove domain', 'error');
            }
        } catch (error) {
            console.error('Failed to remove domain:', error);
            showToast('Failed to remove domain', 'error');
        }
    }
}

// ============================================
// UPDATE FUNCTIONS
// ============================================
async function checkForUpdates() {
    try {
        const response = await fetch('/api/update/check');
        const data = await response.json();
        if (data.hasUpdate) {
            document.getElementById('current-version').textContent = data.current;
            document.getElementById('latest-version').textContent = data.latest;
            document.getElementById('update-modal').classList.remove('hidden');
        } else {
            showToast('You have the latest version! ✅', 'success');
        }
    } catch (error) {
        console.error('Failed to check updates:', error);
    }
}

async function installUpdate() {
    const progressBar = document.getElementById('update-progress-bar');
    const statusText = document.getElementById('update-status');
    const progressDiv = document.getElementById('update-progress');
    progressDiv.classList.remove('hidden');
    statusText.textContent = 'Starting update...';
    progressBar.style.width = '10%';
    
    try {
        const response = await fetch('/api/update/install', { method: 'POST' });
        const data = await response.json();
        const steps = ['Backing up...', 'Pulling changes...', 'Installing dependencies...', 'Restarting server...'];
        let progress = 10;
        for (const step of steps) {
            statusText.textContent = step;
            progress += 20;
            progressBar.style.width = progress + '%';
            await new Promise(resolve => setTimeout(resolve, 1500));
        }
        progressBar.style.width = '100%';
        statusText.textContent = '✅ Update complete! Server will restart...';
        setTimeout(() => {
            showToast('Update installed successfully! 🎉', 'success');
            closeUpdateModal();
            setTimeout(() => window.location.reload(), 2000);
        }, 1000);
    } catch (error) {
        statusText.textContent = '❌ Update failed: ' + error.message;
        progressBar.style.width = '0%';
        showToast('Update failed!', 'error');
    }
}

function closeUpdateModal() {
    document.getElementById('update-modal').classList.add('hidden');
}

// ============================================
// TOAST FUNCTION
// ============================================
function showToast(message, type = 'info') {
    if (app) {
        app.showToast(message, type);
    } else {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// ============================================
// PAGE FUNCTIONS
// ============================================
function switchPage(page) {
    app.switchPage(page);
}

function toggleSidebar() {
    app.toggleSidebar();
}

function refreshPage() {
    app.refreshPage();
}

// ============================================
// INITIALIZATION
// ============================================
const app = new TermuxCPanel();
window.app = app;

const fileManager = new FileManager();
window.fileManager = fileManager;

const gitDeploy = new GitDeployer();
window.gitDeploy = gitDeploy;

const tunnelManager = new TunnelManager();
window.tunnelManager = tunnelManager;

const siteManager = new SiteManager();
window.siteManager = siteManager;

const domainManager = new DomainManager();
window.domainManager = domainManager;

window.showToast = showToast;
window.checkForUpdates = checkForUpdates;
window.installUpdate = installUpdate;
window.closeUpdateModal = closeUpdateModal;
