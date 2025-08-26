# WP Cross Post

A plugin for synchronizing articles between WordPress sites. Automatic synchronization of categories and tags, Material Design UI, REST API v2 compatible.

## Features

- Synchronize articles between multiple WordPress sites
- Automatic synchronization of categories and tags (daily at 3 AM)
- Manual synchronization function
- Category and tag management per site
- Intuitive UI with Material Design
- Support for Japanese domain names
- Fully compatible with REST API v2

## Requirements

- PHP 7.4 or higher
- WordPress 5.8 or higher
- REST API enabled
- Application password function enabled

## Installation

1. Download the plugin
2. Upload and activate the plugin from the WordPress admin panel
3. Set up destination sites from Settings > WP Cross Post

## Configuration

### Adding Sites

1. Issue an application password in the admin panel of the destination site
2. Enter site information in the WP Cross Post settings screen
   - Site name
   - Site URL
   - Username
   - Application password

### Category and Tag Synchronization

- Automatic synchronization: Executed daily at 3 AM
- Manual synchronization: Execute from the "Synchronize Categories and Tags" button in the admin panel
- When adding sites: Automatically synchronize categories and tags of the added sites

### Article Synchronization

1. Select the destination site in the article editing screen
2. Click the "Manual Synchronization" button
3. Confirm synchronization status

## Changelog

### 1.0.9 - 2024-02-XX
- 🖼️ Improved featured image synchronization processing
  - Enhanced image file acquisition method
  - Improved error handling for image upload processing
- 🏷️ Enhanced category and tag synchronization processing
  - Implemented complete transfer of category information
  - Maintained taxonomy hierarchy structure
- 📝 Improved post status reflection
  - Accurate reflection of publication settings (published, draft, private)
  - Proper setting of scheduled post date and time
- 🔄 Enhanced metadata synchronization function
  - Complete synchronization of comment settings, post formats, etc.

### 1.0.8 - 2024-01-XX
- ✨ Enhanced category and tag synchronization function
  - Implementation of taxonomy synchronization compliant with REST API v2
  - Maintained parent category relationships
  - Improved error handling
- 💄 Improved admin panel UI
  - Applied Material Design
  - Enhanced synchronization status display
  - Detailed error messages
- 🐛 Bug fixes
  - Improved error handling during taxonomy synchronization
  - Added Japanese domain normalization processing
  - Improved synchronization processing performance

### 1.0.7 - 2024-01-XX
- ✨ Added automatic synchronization function for categories and tags
- 🎨 Improved admin panel UI (Material Design)
- 🐛 Fixed Japanese domain processing
- ♻️ Fully compatible with REST API v2
- 🔧 Improved synchronization status display

### 1.0.6 - 2024-01-XX
- ✨ Added slug synchronization function
- 🔒 Security enhancement
- 🐛 Improved rate limit handling

### 1.0.5 - 2024-01-XX
- ✨ SWELL theme FAQ block support
- 🎨 Improved block content synchronization

## License

GPL v2 or later

## Author

Watayoshi