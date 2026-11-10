<?php /** Tag <head> bersama untuk PWA (dipakai layout & layout_app). */ ?>
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#E3E5F3" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#12132B" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Peminjaman HME">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="application-name" content="Peminjaman HME">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('img/favicon-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
