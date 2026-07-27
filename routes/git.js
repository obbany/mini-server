const express = require('express');
const router = express.Router();
const path = require('path');
const fs = require('fs-extra');
const { exec } = require('child_process');
const util = require('util');
const execPromise = util.promisify(exec);
const simpleGit = require('simple-git');

router.post('/clone', async (req, res) => {
  try {
    const { url, branch, token, siteName } = req.body;
    
    if (!url) {
      return res.status(400).json({ error: 'Repository URL is required' });
    }
    
    // Validate URL
    const urlPattern = /^(https?:\/\/)?(www\.)?github\.com\/[\w-]+\/[\w-]+/i;
    if (!urlPattern.test(url)) {
      return res.status(400).json({ error: 'Invalid GitHub repository URL' });
    }
    
    // Determine site name from URL if not provided
    let site = siteName;
    if (!site) {
      const parts = url.split('/');
      const repoName = parts[parts.length - 1];
      site = repoName.replace(/\.git$/, '');
    }
    
    // Sanitize site name
    site = site.replace(/[^a-zA-Z0-9-_]/g, '');
    if (!site) {
      return res.status(400).json({ error: 'Invalid site name' });
    }
    
    const sitePath = path.join(__dirname, '..', 'www', site);
    const normalizedPath = path.normalize(sitePath);
    const wwwRoot = path.join(__dirname, '..', 'www');
    
    if (!normalizedPath.startsWith(wwwRoot)) {
      return res.status(403).json({ error: 'Access denied' });
    }
    
    // Prepare clone URL with token if provided
    let cloneUrl = url;
    if (token) {
      const tokenUrl = new URL(url);
      // Add token to URL for authentication
      const tokenPrefix = `x-access-token:${token}@`;
      const protocolEnd = cloneUrl.indexOf('://') + 3;
      cloneUrl = `${cloneUrl.substring(0, protocolEnd)}${tokenPrefix}${cloneUrl.substring(protocolEnd)}`;
    }
    
    // Remove if exists and clone fresh
    if (fs.existsSync(normalizedPath)) {
      await fs.remove(normalizedPath);
    }
    
    const git = simpleGit();
    const branchName = branch || 'main';
    
    await git.clone(cloneUrl, normalizedPath, ['--branch', branchName, '--single-branch', '--depth', '1']);
    
    // Remove .git folder to save space
    const gitDir = path.join(normalizedPath, '.git');
    if (fs.existsSync(gitDir)) {
      await fs.remove(gitDir);
    }
    
    res.json({ 
      success: true, 
      site: site,
      path: `/sites/${site}`,
      branch: branchName,
      message: 'Repository cloned successfully'
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to clone repository', details: error.message });
  }
});

router.post('/pull/:site', async (req, res) => {
  try {
    const { site } = req.params;
    const { branch, token } = req.body;
    const sitePath = path.join(__dirname, '..', 'www', site);
    const normalizedPath = path.normalize(sitePath);
    const wwwRoot = path.join(__dirname, '..', 'www');
    
    if (!normalizedPath.startsWith(wwwRoot) || !fs.existsSync(normalizedPath)) {
      return res.status(404).json({ error: 'Site not found' });
    }
    
    // Check if it's a git repository
    const gitDir = path.join(normalizedPath, '.git');
    if (!fs.existsSync(gitDir)) {
      return res.status(400).json({ error: 'Not a git repository. Use clone instead.' });
    }
    
    const git = simpleGit(normalizedPath);
    
    // Set authentication if token provided
    if (token) {
      const originUrl = await git.remote(['get-url', 'origin']);
      const tokenUrl = new URL(originUrl);
      const tokenPrefix = `x-access-token:${token}@`;
      const protocolEnd = originUrl.indexOf('://') + 3;
      const authUrl = `${originUrl.substring(0, protocolEnd)}${tokenPrefix}${originUrl.substring(protocolEnd)}`;
      await git.remote(['set-url', 'origin', authUrl]);
    }
    
    const branchName = branch || 'main';
    await git.fetch();
    await git.pull('origin', branchName);
    
    res.json({ 
      success: true, 
      site: site,
      branch: branchName,
      message: 'Repository updated successfully'
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to pull repository', details: error.message });
  }
});

// Get repository info
router.post('/info', async (req, res) => {
  try {
    const { url } = req.body;
    
    if (!url) {
      return res.status(400).json({ error: 'Repository URL is required' });
    }
    
    // Parse GitHub URL to get repo info
    const urlPattern = /^(https?:\/\/)?(www\.)?github\.com\/([^/]+)\/([^/]+)/i;
    const match = url.match(urlPattern);
    
    if (!match) {
      return res.status(400).json({ error: 'Invalid GitHub repository URL' });
    }
    
    const [, , , owner, repo] = match;
    const repoName = repo.replace(/\.git$/, '');
    
    res.json({
      success: true,
      owner: owner,
      repo: repoName,
      defaultBranch: 'main',
      suggestedSite: repoName
    });
  } catch (error) {
    res.status(500).json({ error: 'Failed to get repository info', details: error.message });
  }
});

module.exports = router;
