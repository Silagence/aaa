<?php
/**
 * 编辑器页面
 * 三栏布局：左栏素材库+场景列表 / 中栏剧本节点列表 / 右栏节点属性卡片
 * 功能：剧本编写（卡片式节点）、调用内置素材、场景配置、JSON 导入导出、预览跳转
 * 路由：/editor.php
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑器 · Asha</title>
    <link rel="stylesheet" href="assets/css/home.css">
    <link rel="stylesheet" href="assets/css/editor.css">
</head>
<body class="editor-body">
    <!-- 顶部工具栏 -->
    <header class="topbar">
        <div class="topbar__left">
            <a href="index.php" class="topbar__back" title="返回首页">← Asha</a>
            <span class="topbar__sep"></span>
            <input id="workName" class="topbar__title" type="text" value="未命名作品" placeholder="作品名称">
            <button id="btnNewWork" class="btn btn--ghost btn--sm" type="button" title="新建作品（清空当前编辑器内容）">+ 新建</button>
            <span id="saveState" class="topbar__save">已保存</span>
        </div>
        <div class="topbar__right">
            <button id="btnImport" class="btn btn--ghost btn--sm" type="button">导入</button>
            <button id="btnExport" class="btn btn--ghost btn--sm" type="button">导出 JSON</button>
            <button id="btnPreview" class="btn btn--primary btn--sm" type="button">预览</button>
            <input id="fileImport" type="file" accept="application/json,.json,.txt" hidden>
        </div>
    </header>

    <!-- 三栏主体 -->
    <main class="editor">
        <!-- 左栏：素材库 + 场景列表 -->
        <aside class="panel panel--left" id="leftPanel">
            <section class="block">
                <div class="block__head">
                    <h2 class="block__title">素材库</h2>
                    <div class="tabs" id="assetTabs">
                        <button class="tab is-active" data-tab="bg" type="button">背景</button>
                        <button class="tab" data-tab="sprite" type="button">立绘</button>
                        <button class="tab" data-tab="bgm" type="button">BGM</button>
                        <button class="tab" data-tab="sfx" type="button">音效</button>
                    </div>
                </div>
                <div class="block__body">
                    <div id="spriteFilter" class="sprite-filter" hidden>
                        <input id="spriteSearch" class="field__input field__input--sm" type="search"
                               placeholder="搜索角色名…" autocomplete="off">
                        <div id="spriteCats" class="sprite-cats"></div>
                    </div>
                    <div id="assetGrid" class="asset-grid"></div>
                </div>
            </section>

            <section class="block block--grow">
                <div class="block__head">
                    <h2 class="block__title">场景列表</h2>
                    <button id="btnAddScene" class="btn btn--ghost btn--xs" type="button">+ 新建场景</button>
                </div>
                <div class="block__body">
                    <ul id="sceneList" class="scene-list"></ul>
                </div>
            </section>
        </aside>

        <!-- 中栏：节点列表 -->
        <section class="panel panel--center">
            <div class="center__head">
                <div class="center__scene-info">
                    <label class="field field--inline">
                        <span class="field__label">场景ID</span>
                        <input id="sceneId" class="field__input" type="text" placeholder="scene_001">
                    </label>
                    <button id="btnDelScene" class="btn btn--ghost btn--xs" type="button" title="删除当前场景">删除场景</button>
                </div>
                <div class="insertbar">
                    <span class="insertbar__label">插入节点：</span>
                    <button class="chip" data-insert="bg" type="button">背景</button>
                    <button class="chip" data-insert="sprite" type="button">立绘</button>
                    <button class="chip" data-insert="bgm" type="button">BGM</button>
                    <button class="chip" data-insert="sfx" type="button">音效</button>
                    <button class="chip" data-insert="say" type="button">对话</button>
                    <button class="chip" data-insert="choose" type="button">选项</button>
                    <button class="chip" data-insert="var" type="button">变量</button>
                    <button class="chip" data-insert="goto" type="button">跳转</button>
                </div>
            </div>
            <div class="center__body" id="nodeList"></div>
            <div class="center__foot" id="emptyHint">
                <p>当前场景暂无节点。</p>
                <p>使用上方按钮插入节点，或从左侧素材库点击素材快速添加。</p>
            </div>
        </section>

        <!-- 右栏：节点属性卡片 -->
        <aside class="panel panel--right" id="rightPanel">
            <section class="block">
                <div class="block__head">
                    <h2 class="block__title">节点属性</h2>
                </div>
                <div class="block__body" id="propsPanel">
                    <p class="placeholder">选中一个节点以编辑其属性。</p>
                </div>
            </section>

            <section class="block">
                <div class="block__head">
                    <h2 class="block__title">作品信息</h2>
                </div>
                <div class="block__body">
                    <label class="field">
                        <span class="field__label">作者</span>
                        <input id="workAuthor" class="field__input" type="text" placeholder="作者名">
                    </label>
                    <label class="field">
                        <span class="field__label">起始场景</span>
                        <select id="workStartScene" class="field__input"></select>
                    </label>
                    <label class="field">
                        <span class="field__label">画布宽度</span>
                        <input id="canvasW" class="field__input" type="number" value="1280">
                    </label>
                    <label class="field">
                        <span class="field__label">画布高度</span>
                        <input id="canvasH" class="field__input" type="number" value="720">
                    </label>
                </div>
            </section>
        </aside>
    </main>

    <!-- 导出预览模态框 -->
    <div id="exportModal" class="modal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>导出配置文件</h3>
                <button id="closeModal" class="modal__close" type="button">×</button>
            </div>
            <div class="modal__body">
                <textarea id="exportArea" class="export-area" readonly></textarea>
            </div>
            <div class="modal__foot">
                <button id="btnCopyJson" class="btn btn--ghost btn--sm" type="button">复制</button>
                <button id="btnDownload" class="btn btn--primary btn--sm" type="button">下载 .json</button>
            </div>
        </div>
    </div>

    <!-- Toast 提示 -->
    <div id="toast" class="toast" hidden></div>

    <script src="assets/js/editor.js"></script>
</body>
</html>
