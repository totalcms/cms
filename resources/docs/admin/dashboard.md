---
title: "Admin Dashboard Overview"
description: "Navigate the Total CMS admin dashboard to manage collections, edit content, configure settings, and use the built-in form builder."
---

# Admin Dashboard Overview

The Total CMS Admin Dashboard is your central hub for managing all content, users, and system settings. This guide will help you navigate and utilize the dashboard effectively.

(This primarily serves as an outline for future docs...)

## Dashboard Layout

The admin interface is organized into several main sections:

### 1. Navigation Menu
Located on the left side, the navigation menu provides quick access to:

- **Dashboard** - Overview and quick stats
- **Collections** - Manage your content types
- **Schemas** - Define and edit data structures
- **Documentation** - This help system
- **Tools** - Utilities like import/export, job queue
- **Settings** - System configuration

### 2. Main Content Area
The central area displays the current page content, forms, and data tables.

### 3. Top Bar
Contains:
- Search functionality
- User profile menu
- Quick actions
- System notifications

## Key Features

### Collection Management

Collections are the heart of Total CMS. From the dashboard, you can:

1. **View All Collections** - See all available content types at a glance
2. **Quick Actions** - Add new content, view collection, or edit schema

Example collections include:
- Blog posts
- Image galleries
- File repositories
- Custom business objects

### Data Tables

Most collection views use interactive data tables that support:

- **Sorting** - Click column headers to sort
- **Filtering** - Search within collections
- **Pagination** - Navigate large datasets
- **Bulk Actions** - Select multiple items for operations
- **Quick Edit** - Inline editing for simple changes (see below)

### Editing in the Table

Simple values can be changed without opening the object. Hover a cell and a
pencil appears at its right edge; click it and the cell becomes that one
field, with a check mark to save and a cross to cancel beside it. Enter saves
from a single-line input, Escape cancels, and the cell shows the new value as
soon as the save lands. While a cell is open, no other cell offers a pencil.
Nothing else on the page reloads, and the value is saved exactly as the
object form would save it: a toggle stays a boolean, a list stays a list,
rich text stays HTML.

The pencil appears on text, textarea, number, range, price, toggle,
checkbox, select, radio, multiselect, checklist, date, datetime, time, url,
email, phone, color, list and styled text fields. Identity fields (`id`,
slugs), passwords and secrets, readonly fields such as the created and
updated timestamps, and composites (images, galleries, files, depots, decks,
cards, code) do not offer it: open the object to edit those. A field the
current user's access group cannot change is refused the same way it is on
the object form.

### Form Builder

The form system automatically generates input forms based on your schemas:

- **Smart Field Types** - Appropriate inputs for each data type
- **Validation** - Real-time and server-side validation
- **File Uploads** - Drag-and-drop media uploads
- **Rich Text Editing** - WYSIWYG editor for content
- **Relationships** - Link content between collections

### Activity Feed

The dashboard homepage shows recent activity:

- Recently modified objects
- User login activity
- System events
- Job queue status

## Common Tasks

### Creating Content

1. Navigate to the desired collection
2. Click "Add New" button
3. Fill in the form fields
4. Save or Save & Continue

### Editing Content

1. Find the content in the collection view
2. Click the edit icon or row
3. Modify the fields
4. Save changes

### Bulk Operations

1. Select items using checkboxes
2. Choose action from dropdown
3. Confirm the operation

### Media Management

1. Go to Media section
2. Upload files via drag-and-drop
3. Organize into folders
4. Use in content via media picker

## Dashboard Widgets

The main dashboard page can display various widgets:

### Statistics Widget
- Total objects across collections
- Storage usage
- User count
- Recent activity metrics

### Quick Links Widget
- Frequently used collections
- Recent drafts
- Pending approvals

### System Status Widget
- License status
- Job queue health
- Cache status
- System updates

## Customization

### User Preferences

Access your preferences from the user menu:

- Dashboard layout
- Default collection view
- Items per page
- Date/time format
- Language settings

### Collection Views

Customize how collections display:

- Choose visible columns
- Set default sorting
- Configure filters
- Save view presets

## Performance Tips

1. **Use Filters** - Filter large collections before sorting
2. **Pagination** - Adjust items per page for performance
3. **Bulk Actions** - Process multiple items at once
4. **Keyboard Shortcuts** - Learn shortcuts for common actions

## Getting Help

- **Tooltips** - Hover over icons for explanations
- **Field Help** - Click (?) icons for field descriptions
- **Documentation** - Access full docs from the menu
- **Support** - Contact support from the help menu

## Security Notes

- Sessions expire after inactivity
- All actions are logged
- Sensitive operations require confirmation
- Regular backups are recommended

Remember: The dashboard is designed to be intuitive. Don't hesitate to explore and experiment - most actions can be undone or have confirmation prompts for safety.