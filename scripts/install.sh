#!/bin/bash

# Termux CPanel Installation Script
# This script sets up the complete environment for Termux CPanel

set -e

echo "🚀 Termux CPanel Installation Script"
echo "===================================="
echo ""

# Check if running in Termux
if [ ! -d "$HOME/.termux" ]; then
    echo "⚠️  Warning: This script is designed for Termux on Android."
    echo "   Some features may not work correctly on other platforms."
    echo ""
fi

# Update and upgrade packages
echo "📦 Updating package lists..."
pkg update -y

echo "📦 Upgrading installed packages..."
pkg upgrade -y

# Install required packages
echo "📦 Installing required packages..."
pkg install -y nodejs git cloudflared python

# Install npm packages globally
echo "📦 Installing npm packages..."
npm install -g pm2

# Create project structure
echo "📁 Creating project structure..."
mkdir -p ~/termux-cpanel
cd ~/termux-cpanel

# Create necessary directories
mkdir -p public/css public/js routes scripts www

# Install node dependencies
echo "📦 Installing Node.js dependencies..."
npm install express cors body-parser multer simple-git systeminformation compression helmet fs-extra archiver chokidar

# Create start script
echo "📝 Creating start script..."
cat > start.sh << 'EOF'
#!/bin/bash
cd ~/termux-cpanel

# Check if cloudflared is installed
if ! command -v cloudflared &> /dev/null; then
    echo "⚠️  cloudflared not found. Installing..."
    pkg install cloudflared -y
fi

# Check if node_modules exists
if [ ! -d "node_modules" ]; then
    echo "📦 Installing dependencies..."
    npm install
fi

# Start the server
echo "🚀 Starting Termux CPanel..."
echo "   Access at: http://localhost:3000"
echo ""

# Use pm2 if available, otherwise use nohup
if command -v pm2 &> /dev/null; then
    pm2 start server.js --name "termux-cpanel"
    pm2 save
    pm2 logs termux-cpanel
else
    nohup node server.js > server.log 2>&1 &
    echo "ℹ️  Server started in background with PID: $!"
    echo "   To view logs: tail -f server.log"
fi
EOF

chmod +x start.sh

# Create install verification script
echo "📝 Creating verification script..."
cat > verify.sh << 'EOF'
#!/bin/bash
echo "🔍 Verifying Termux CPanel Installation..."
echo ""

# Check Node.js
if command -v node &> /dev/null; then
    echo "✅ Node.js: $(node --version)"
else
    echo "❌ Node.js not found"
fi

# Check npm
if command -v npm &> /dev/null; then
    echo "✅ npm: $(npm --version)"
else
    echo "❌ npm not found"
fi

# Check Git
if command -v git &> /dev/null; then
    echo "✅ Git: $(git --version)"
else
    echo "❌ Git not found"
fi

# Check cloudflared
if command -v cloudflared &> /dev/null; then
    echo "✅ cloudflared: $(cloudflared --version)"
else
    echo "❌ cloudflared not found"
fi

# Check project structure
echo ""
echo "📁 Project Structure:"
ls -la ~/termux-cpanel

echo ""
echo "🔧 Quick Start:"
echo "   cd ~/termux-cpanel"
echo "   ./start.sh"
echo ""
echo "🌐 Access Control Panel:"
echo "   http://localhost:3000"
EOF

chmod +x verify.sh

# Create a sample site
echo "📝 Creating sample site..."
mkdir -p www/example
cat > www/example/index.html << 'EOF'
<!DOCTYPE html>
<html>
<head>
    <title>Welcome to Termux CPanel</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #0f172a; color: #e2e8f0; }
        h1 { color: #818cf8; }
        a { color: #a5b4fc; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { background: rgba(30, 41, 59, 0.4); padding: 2rem; border-radius: 1rem; border: 1px solid rgba(99, 102, 241, 0.1); }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Welcome to Termux CPanel</h1>
        <p>Your Android is now a web server!</p>
        <p>Hosted with Node.js and Cloudflare Tunnels.</p>
        <hr>
        <p><small>Powered by Termux CPanel v1.0.0</small></p>
    </div>
</body>
</html>
EOF

echo ""
echo "✅ Installation Complete!"
echo "===================================="
echo ""
echo "📌 Next Steps:"
echo "1. Start the control panel:"
echo "   cd ~/termux-cpanel && ./start.sh"
echo ""
echo "2. Access the control panel:"
echo "   http://localhost:3000"
echo ""
echo "3. Verify installation:"
echo "   ./verify.sh"
echo ""
echo "4. Set up Cloudflare Tunnel:"
echo "   cloudflared tunnel login"
echo "   cloudflared tunnel create my-tunnel"
echo ""
echo "📱 To keep server running in background:"
echo "   termux-wake-lock"
echo ""

# Optional: Offer to start the server
read -p "Start the server now? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    ./start.sh
fi
