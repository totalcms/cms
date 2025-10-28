# Total CMS Demo

This directory contains a comprehensive demonstration of Total CMS 3 features and capabilities.

## Demo Page: `index.php`

A fully functional demo website showcasing various Total CMS features including:

### Featured Sections

1. **Hero Section**
   - Dynamic header text from CMS
   - Featured hero image with image processing

2. **Featured Blog Posts**
   - Displays featured blog posts from the demo data
   - Shows post images, titles, authors, dates, and excerpts
   - Demonstrates filtering and slicing collections

3. **Products Grid**
   - Product catalog with images and pricing
   - Tag display system
   - Responsive grid layout

4. **Photo Gallery**
   - Interactive gallery with lightbox functionality
   - Configurable columns and spacing
   - Demonstrates Total CMS gallery system

5. **Blog List with Pagination**
   - Full blog listing with `{% cmsgrid %}` tag
   - Pagination support (5 posts per page)
   - Category and date display

6. **Field Types Demo**
   - Text fields
   - Email fields
   - URL fields with links
   - Number fields (price format)
   - Date fields with formatting
   - Color fields with visual swatch
   - Toggle fields with on/off display
   - Styled text (rich text) fields

7. **Code Snippets**
   - Displays reusable Twig snippets from the playground collection
   - Shows how to organize and display code examples

## Setup

1. **Install Total CMS**
   - Ensure the latest version of the dist is installed into /tcms

2. **Load Demo Data**
   - Demo data is included in the JumpStart system
   - Use the admin panel to import demo data if needed
   - Navigate to: Admin → Utils → JumpStart

3. **View the Demo**
   - Access via web server: `http://your-site.com/demo/index.php`
   - Or use PHP's built-in server:
     ```bash
     php -S localhost:8000 -t .
     # Then visit: http://localhost:8000/demo/index.php
     ```

## Demo Data Used

The demo page uses data from these collections:

- **blog** - Blog posts with featured flag, images, authors, dates, and categories
- **products** - Product catalog with prices, images, and tags
- **gallery** - Photo gallery with demo images
- **playground** - Code snippets and reusable content
- **text** - Simple text fields (demoname, demoheader)
- **email** - Email address (demoemail)
- **url** - Website URL (demourl)
- **number** - Price/number value (demoprice)
- **date** - Date field (demodate)
- **color** - Color picker (democolor)
- **toggle** - Boolean toggle (demotoggle)
- **styledtext** - Rich text content (demostyledtext)
- **image** - Hero image (demoimage)

## Features Demonstrated

### CMS Functions
- `cms.text()` - Simple text retrieval
- `cms.email()` - Email field
- `cms.url()` - URL field
- `cms.number()` - Number field
- `cms.date()` - Date field
- `cms.color()` - Color field
- `cms.toggle()` - Boolean toggle
- `cms.styledtext()` - Rich text content
- `cms.image()` - Image with processing options
- `cms.gallery()` - Gallery display
- `cms.objects()` - Collection retrieval with filtering

### Advanced Features
- **CMS Grid** - Pagination and list display
- **Image Processing** - Resize, crop, fit options
- **Twig Filters** - date formatting, striptags, truncate, title, join
- **Collection Filtering** - Featured posts, slicing, category filters
- **Responsive Design** - Mobile-friendly layouts
- **Modern CSS** - CSS variables, grid layouts, flexbox

### Styling
- Modern, professional design
- Responsive grid layouts
- Card-based components
- Gradient headers
- Smooth transitions and hover effects
- Mobile-optimized

## Customization

The demo page can be customized by:

1. **Editing Content** - Use the Total CMS admin panel to edit demo content
2. **Styling** - Modify the CSS in the `demo.css` file
3. **Layout** - Adjust the HTML structure and Twig code
4. **Data** - Create new collections or modify existing demo data

## Learning Resources

Use this demo to learn:
- How to structure a Total CMS website
- Best practices for using Twig templates
- Collection filtering and pagination
- Image processing and galleries
- Form field integration
- Responsive design patterns

## Notes

- All CSS and JavaScript assets are loaded from the CMS API
- The page uses Total CMS's built-in content styles and gallery JavaScript
- Data is pulled dynamically from the tcms-data directory
- The demo works with the default JumpStart demo data
