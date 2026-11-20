<?php

use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
| SEO endpoints outside /api/v1 (no session, cookies or CSRF): dynamic
| sitemaps and link-preview pages for social bots. See SeoController.
*/

Route::get('sitemaps/index.xml', [SeoController::class, 'sitemapIndex'])->name('seo.sitemap.index');
Route::get('sitemaps/events.xml', [SeoController::class, 'sitemapEvents'])->name('seo.sitemap.events');
Route::get('sitemaps/vendors.xml', [SeoController::class, 'sitemapVendors'])->name('seo.sitemap.vendors');
Route::get('share/events/{slug}', [SeoController::class, 'shareEvent'])->where('slug', '[A-Za-z0-9\-_]+')->name('seo.share.event');
Route::get('share/vendors/{slug}', [SeoController::class, 'shareVendor'])->where('slug', '[A-Za-z0-9\-_]+')->name('seo.share.vendor');
