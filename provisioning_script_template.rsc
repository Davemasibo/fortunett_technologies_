# MikroTik Router Auto-Provisioning Script Template
# This script should be run on the MikroTik router to auto-register with the ISP management system
# Replace {{PROVISIONING_TOKEN}} with the actual token from your admin dashboard

:local Token "{{PROVISIONING_TOKEN}}"
:local ApiUrl "https://fortunetttech.site/api/routers/auto_register.php"

# Get router information
:local RouterIP [/ip address get [find interface=ether1] address]
:local RouterMAC [/interface ethernet get ether1 mac-address]
:local RouterIdentity [/system identity get name]

# Extract IP address without CIDR notation
:local IPAddress [:pick $RouterIP 0 [:find $RouterIP "/"]]

# Prepare POST data
:local PostData "provisioning_token=$Token&router_ip=$IPAddress&router_mac=$RouterMAC&router_identity=$RouterIdentity&router_username=admin&router_password={{ROUTER_PASSWORD}}"

# Log the registration attempt
:log info "Attempting router registration with ISP management system..."

# Make HTTP POST request
/tool fetch url=$ApiUrl mode=http http-method=post http-data=$PostData keep-result=no

# Log success
:log info "Router registration request sent successfully. Check admin portal for confirmation."

# Display message
:put "Router provisioning initiated. Your router should appear in the admin portal shortly."
