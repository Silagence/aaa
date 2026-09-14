<?php
/**
 * 认证页公共头部
 *
 * @var string $title 页面标题
 */
$title = $title ?? 'Dramatool';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · Dramatool</title>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/home.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/auth.css')) ?>">
</head>
<body class="auth-body">
    <div class="bg-layer" aria-hidden="true">
        <div class="bg-grid"></div>
        <div class="bg-orb bg-orb--1"></div>
        <div class="bg-orb bg-orb--2"></div>
        <div class="bg-orb bg-orb--3"></div>
    </div>

    <main class="auth">
        <a class="auth__brand" href="<?= e(base_url('/')) ?>">Dramatool</a>
        <div class="auth__card">
