=== Auto Internal Links ===
Contributors:       nmtnguyen56
Donate link:        https://err-mouse.id.vn/donate
Tags:               internal links, auto links, seo, internal linking, link building
Requires at least:  5.2
Tested up to:       6.9
Stable tag:         1.0.0
Requires PHP:       7.2
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Automatically find keywords matching post titles and add internal links to improve SEO and user navigation.

== Description ==

**Auto Internal Links** is a lightweight and powerful SEO tool that automatically scans your post content and inserts internal links whenever it finds a keyword that matches the title of another published post on your site. 

Internal linking is crucial for SEO, and this plugin saves you hours of manual work by automating the process efficiently without modifying your database content.

### Core Features:
* **Automatic Linking:** Automatically converts text matching other post titles into internal links.
* **Limit 1 Link Per Post:** Ensures the same URL is only linked once per article to prevent spammy links.
* **Smart Parsing:** Ignores text inside HTML tags like `<a>`, `<h1>` to `<h6>`, `<script>`, `<pre>`, `<code>`, etc., to avoid breaking your site layout or nesting links.
* **Highly Configurable:**
    * Choose which **Post Types** the plugin applies to (Posts, Pages, Custom Post Types).
    * **Exclude Specific Posts** by ID if you don't want them to receive automatic links.
    * Set a **Minimum Title Length** so short/common words aren't accidentally linked.
    * Toggle **Case Sensitivity** for exact matching.
* **Statistics Dashboard:** View a detailed report of which keywords are getting links and easily see exactly which posts contain those links.
* **High Performance:** Uses WordPress Transients (Caching) to store keyword lists and statistics, ensuring your site remains lightning fast even with thousands of articles.
* **Translation Ready:** Fully compatible with localization. English is the default language, and a Vietnamese translation file is included.

== Installation ==

1. Upload the `auto-internal-links` folder to the `/wp-content/plugins/` directory, or install the plugin directly through the WordPress plugins screen by uploading the `.zip` file.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Auto Links -> Settings** in the left admin menu to configure the plugin to your needs.
4. Go to **Auto Links -> Statistics** to view the auto-generated internal links data.

== Frequently Asked Questions ==

= Will this plugin modify my database content? =
No. The plugin uses the `the_content` filter to add links dynamically when the page loads. Your original post content in the database remains completely untouched.

= Will it slow down my website? =
No. The plugin is highly optimized. It uses WordPress Transients to cache the list of post titles for 12 hours. The statistics page also caches data to prevent heavy database queries on large sites.

= Why are some words not being linked? =
Please check your settings. Words shorter than the "Minimum title length" will be ignored. Also, the plugin ensures that a specific URL is only linked once per post, and it will intentionally ignore texts inside headings, links, and code blocks.

= How do I translate this plugin? =
The plugin is fully localized. You can use software like Poedit or a plugin like Loco Translate to translate it into your language using the provided `.pot` file in the `/languages/` directory.

== Screenshots ==

1. The Statistics dashboard showing linked keywords and their count.
2. The detailed view of which posts contain the auto-generated links.
3. The Settings page to configure post types, exclusions, and matching rules.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added core automatic internal linking functionality.
* Added settings panel (Post Types, Exclude IDs, Minimum Length, Case Sensitivity).
* Added statistics dashboard with caching.
* Added English and Vietnamese language support.