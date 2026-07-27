#!/bin/bash

# Termux CPanel Startup Script
# This script starts the control panel with proper background handling

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}================================${NC}"
echo -e "${BLUE}🚀 Termux CPanel Startup Script${NC}"
echo -e "${BLUE}================================${NC}"

# Change to project directory
cd ~/termux-cpanel

# Check if server is already running
if pgrep -f "node server.js" > /dev/null; then
    echo -e "${YELLOW}⚠️  Server is already running${NC}"
    echo -e "   To stop it: pkill -f 'node server.js'"
    echo -e "   Or use: pm2 stop termux-cpanel"
    echo ""
    echo -e "${GREEN}Access control panel at: http://localhost:3000${NC}"
    exit 0
fi

# Install dependencies if missing
if [ ! -d "node_modules" ]; then
    echo -e "${YELLOW}📦 Installing dependencies...${NC}"
    npm install
fi

# Check for termux-wake-lock
if command -v termux-wake-lock &> /dev/null; then
    echo -e "${BLUE}🔒 Acquiring wake lock...${NC}"
    termux-wake-lock
else
    echo -e "${YELLOW}⚠️  termux-wake-lock not found. Server may be paused in background.${NC}"
    echo -e "   Install termux-api for better background execution."
fi

# Check for pm2
if command -v pm2 &> /dev/null; then
    echo -e "${BLUE}📊 Using PM2 process manager${NC}"
    
    # Check if already registered with PM2
    if pm2 list | grep -q "termux-cpanel"; then
        echo -e "${GREEN}🔄 Restarting existing PM2 process...${NC}"
        pm2 restart termux-cpanel
    else
        echo -e "${GREEN}🚀 Starting with PM2...${NC}"
        pm2 start server.js --name "termux-cpanel" --watch
        pm2 save
    fi
    
    echo -e "${GREEN}✅ Server started with PM2${NC}"
    echo -e "   To view logs: pm2 logs termux-cpanel"
    echo -e "   To stop: pm2 stop termux-cpanel"
    echo -e "   To restart: pm2 restart termux-cpanel"
else
    echo -e "${BLUE}📋 Using nohup (background process)${NC}"
    
    # Start server in background
    nohup node server.js > server.log 2>&1 &
    SERVER_PID=$!
    
    echo -e "${GREEN}✅ Server started with PID: $SERVER_PID${NC}"
    echo -e "   To view logs: tail -f server.log"
    echo -e "   To stop: kill $SERVER_PID"
fi

echo ""
echo -e "${GREEN}================================${NC}"
echo -e "${GREEN}🌐 Server is running!${NC}"
echo -e "${GREEN}================================${NC}"
echo -e "   📱 Access control panel: ${BLUE}http://localhost:3000${NC}"
echo -e "   📁 Hosted sites: ${BLUE}http://localhost:3000/sites/${NC}"
echo ""
echo -e "${YELLOW}💡 Quick Tips:${NC}"
echo -e "   1. To check status: ${BLUE}pm2 status${NC} or ${BLUE}ps aux | grep node${NC}"
echo -e "   2. Cloudflare Tunnel: ${BLUE}cloudflared tunnel --url http://localhost:3000${NC}"
echo -e "   3. Keep server running: ${BLUE}termux-wake-lock${NC}"
echo -e "   4. To stop: ${BLUE}pkill -f 'node server.js'${NC}"

# Check if public directory exists
if [ ! -d "public" ]; then
    echo -e "${YELLOW}⚠️  Warning: public directory not found.${NC}"
    echo -e "   Make sure the project is properly set up."
fi

# Check if www directory exists
if [ ! -d "www" ]; then
    echo -e "${YELLOW}⚠️  Warning: www directory not found. Creating...${NC}"
    mkdir -p www
fi

echo ""

# Check for Cloudflare tunnel if running
if command -v cloudflared &> /dev/null; then
    echo -e "${BLUE}🔄 Checking for Cloudflare tunnel...${NC}"
    if pgrep -f "cloudflared tunnel" > /dev/null; then
        echo -e "${GREEN}✅ Cloudflare tunnel is running${NC}"
    else
        echo -e "${YELLOW}ℹ️  Cloudflare tunnel not running${NC}"
        echo -e "   Start a tunnel: ${BLUE}cloudflared tunnel --url http://localhost:3000${NC}"
        echo -e "   Or use the control panel's Cloudflare Tunnel page."
    fi
fi

echo ""
echo -e "${BLUE}================================${NC}"
echo -e "${GREEN}🎉 Termux CPanel is ready!${NC}"
echo -e "${BLUE}================================${NC}"

# Open browser if possible
if command -v termux-open &> /dev/null; then
    echo -e "${BLUE}🌐 Opening browser...${NC}"
    termux-open http://localhost:3000
fi
