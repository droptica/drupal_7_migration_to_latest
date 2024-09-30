# Tools to Improve Drupal 7 Migration to Drupal 10/11

## Droptica Drupal 7 Audit Tool

This script extracts metadata from a Drupal 7 site and generates an audit file. The audit file includes detailed information about the site's content types, taxonomies, users, fields, modules, themes, and file types.

### Features

- **General Information**: Provides the Drupal version, site name, document root, and database name.
- **Node Types and Counts**: Lists all node types and their counts, including the number of nodes created in the last year.
- **Taxonomy Vocabulary Types and Terms**: Lists all taxonomy vocabularies and the number of terms in each.
- **User Roles and Counts**: Lists all user roles and the number of users assigned to each role.
- **Fields for Each Node Type**: Lists all fields associated with each node type.
- **Module Information**:
    - Counts the number of contributed and custom modules in the codebase.
    - Lists installed contributed and custom modules.
    - Provides detailed information about custom modules, including the number of PHP and inc files, total lines of PHP code, file extensions, custom entities, database queries, and functions.
- **File Information**: Groups files by MIME type and counts the number of files for each type.
- **Theme Information**:
    - Lists all themes in the codebase.
    - Provides detailed information about each theme, including the base theme, number of JS and CSS files, total lines of JS and CSS code, number of templates, and PHP percentage in templates.

### Usage

Check instruction and example video on this blog post: https://www.droptica.com/blog/curious-about-drupal-7-11-migration-costs-collect-all-info-estimation-5-minutes/ 

