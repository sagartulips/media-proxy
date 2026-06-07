# Local Development Media Proxy

A WordPress plugin that proxies media requests from your production site during local development, with advanced cache management.

## Features

- 🖼️ **Media URL Rewriting**: Seamless media proxying from production to local
- 🔄 **CORS Support**: Built-in CORS header management
- 🧹 **Smart Cache Control**: Multiple cache clearing modes
- ⚡ **Auto Cache Busting**: Automatic cache invalidation
- ⚙️ **Flexible Configuration**: Admin panel or wp-config.php constants

## Installation

1. **Method 1 (WordPress Admin)**:
   - Download the ZIP file
   - Go to **Plugins → Add New → Upload Plugin**
   - Upload the ZIP file and activate

2. **Method 2 (Manual)**:
   - Upload `local-dev-media-proxy` folder to `/wp-content/plugins/`
   - Activate in WordPress admin

3. **Method 3 (Git)**:
   ```bash
   cd /wp-content/plugins/
   git clone https://your-repo-url/local-dev-media-proxy.git
   ```

## Configuration

### Admin Panel Settings
1. Go to **Settings → Media Proxy**
2. Configure:
   - **Local URL**: Your local development URL (e.g., `https://site.local`)
   - **Live URL**: Production site URL (e.g., `https://site.com`)
   - **Cache Settings**: Choose clearing behavior
   - **Auto Cache Busting**: Enable/disable automatic cache invalidation

### wp-config.php Constants (Alternative)
```php
// Override admin settings
define('LOCAL_DEV_MEDIA_LOCAL_URL', 'https://site.local');
define('LOCAL_DEV_MEDIA_LIVE_URL', 'https://site.com');
define('LOCAL_DEV_MEDIA_ENABLE_CORS', true);
define('LOCAL_DEV_MEDIA_AUTO_CACHE_BUST', true);
```

## Cache Management

### Clearing Modes
| Mode | Trigger | Clears |
|------|---------|--------|
| **Media Only** | `?clear_key=media` | Media URLs |
| **Specific** | `?clear_key=media,object` | Selected caches |
| **All** | `?clear_key=true` | All caches |

### Admin Tools
- **Quick Clear Buttons**: One-click cache clearing
- **Admin Bar Shortcut**: Clear cache from any page
- **Cache Busting**: Automatic asset versioning after clears

## Troubleshooting

**Media Not Loading?**
1. Verify URLs in settings
2. Check browser console for CORS errors
3. Clear relevant caches

**Cache Not Updating?**
1. Try different clearing modes
2. Check if caching plugins are interfering
3. Verify auto cache busting is enabled

## Development

```bash
# Clone repository
git clone https://your-repo-url/local-dev-media-proxy.git

# Build plugin ZIP
zip -r local-dev-media-proxy.zip local-dev-media-proxy -x "*.git*"
```

## Changelog

### 1.4
- Added auto cache busting
- Enhanced admin interface
- Improved cache clearing logic

### 1.3
- Added multiple cache clearing modes
- Admin bar integration
- Better constant handling

## License

GPLv2 or later