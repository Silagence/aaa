<?php
/**
 * 编辑器页面视图
 * 三栏布局：左栏素材库+场景列表 / 中栏剧本节点列表 / 右栏节点属性卡片
 * 功能：剧本编写（卡片式节点）、调用内置素材、场景配置、JSON 导入导出、预览跳转
 *
 * @var array|null $user 当前登录用户
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑器 · Dramatool</title>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/home.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/editor.css')) ?>">
</head>
<body class="editor-body">
    <!-- 顶部工具栏 -->
    <header class="topbar">
        <div class="topbar__left">
            <a href="<?= e(base_url('/')) ?>" class="topbar__back" title="返回首页">← Dramatool</a>
            <span class="topbar__sep"></span>
            <input id="workName" class="topbar__title" type="text" value="未命名作品" placeholder="作品名称">
            <button id="btnNewWork" class="btn btn--ghost btn--sm" type="button" title="新建作品（清空当前编辑器内容）">+ 新建</button>
            <span id="saveState" class="topbar__save">已保存</span>
        </div>
        <div class="topbar__right">
            <button id="btnUndo" class="btn btn--ghost btn--sm" type="button" title="撤销 (Ctrl+Z)" disabled>↶ 撤销</button>
            <button id="btnRedo" class="btn btn--ghost btn--sm" type="button" title="重做 (Ctrl+Shift+Z)" disabled>↷ 重做</button>
            <span class="topbar__sep"></span>
            <button id="btnImport" class="btn btn--ghost btn--sm" type="button">导入</button>
            <button id="btnExport" class="btn btn--ghost btn--sm" type="button">导出 JSON</button>
            <button id="btnCloudSave" class="btn btn--ghost btn--sm" type="button">保存到云端</button>
            <button id="btnHistory" class="btn btn--ghost btn--sm" type="button">历史版本</button>
            <button id="btnPreview" class="btn btn--ghost btn--sm" type="button">预览</button>
            <button id="btnPublish" class="btn btn--primary btn--sm" type="button" title="发布到广场并生成分享短链">发布</button>
            <button id="btnTheme" class="btn btn--ghost btn--sm" type="button" title="切换主题（深色 / 浅色 / 护眼 / 跟随系统）">主题</button>
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
                    <div class="asset-viewbar">
                        <div class="tabs" id="assetTabs">
                            <button class="tab is-active" data-tab="bg" type="button">背景</button>
                            <button class="tab" data-tab="sprite" type="button">立绘</button>
                            <button class="tab" data-tab="bgm" type="button">BGM</button>
                            <button class="tab" data-tab="sfx" type="button">音效</button>
                        </div>
                        <div class="tabs tabs--icon" id="assetViewTabs">
                            <button class="tab is-active" data-view="grid" type="button" title="网格视图">▦</button>
                            <button class="tab" data-view="list" type="button" title="列表视图">☰</button>
                        </div>
                    </div>
                </div>
                <div class="block__body">
                    <div class="asset-sourcebar">
                        <div class="tabs tabs--sm" id="assetSourceTabs">
                            <button class="tab is-active" data-source="builtin" type="button">内置素材</button>
                            <button class="tab" data-source="mine" type="button">我的素材</button>
                        </div>
                        <button id="btnUploadAsset" class="btn btn--ghost btn--xs" type="button"
                                title="上传图片或音频作为素材">+ 上传</button>
                    </div>
                    <div id="assetUsage" class="asset-usage" hidden></div>
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
                    <button class="chip" data-insert="spriteRemove" type="button">移除立绘</button>
                    <button class="chip" data-insert="bgm" type="button">BGM</button>
                    <button class="chip" data-insert="sfx" type="button">音效</button>
                    <button class="chip" data-insert="say" type="button">对话</button>
                    <button class="chip" data-insert="choose" type="button">选项</button>
                    <button class="chip" data-insert="var" type="button">变量</button>
                    <button class="chip" data-insert="goto" type="button">跳转</button>
                </div>
            </div>
            <div class="center__viewbar">
                <div class="tabs" id="viewTabs">
                    <button class="tab is-active" data-view="nodes" type="button">节点</button>
                    <button class="tab" data-view="outline" type="button">大纲</button>
                </div>
                <span class="center__viewhint" id="viewHint"></span>
            </div>
            <div class="center__body" id="nodeList"></div>
            <div class="center__body center__body--outline" id="outlineList" hidden></div>
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
                    <label class="field">
                        <span class="field__label">作品简介 <span class="field__hint">发布后展示在广场与详情页</span></span>
                        <textarea id="workDesc" class="field__input field__input--area" maxlength="200"
                                  placeholder="一句话介绍你的作品…"></textarea>
                    </label>
                    <label class="field">
                        <span class="field__label">标签 <span class="field__hint">逗号分隔，最多 5 个</span></span>
                        <input id="workTags" class="field__input" type="text"
                               placeholder="如：悬疑, 校园, 短篇">
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
                <div id="exportTabs" class="tabs tabs--sm">
                    <button class="tab is-active" type="button" data-format="json">JSON</button>
                    <button class="tab" type="button" data-format="txt">TXT 脚本</button>
                </div>
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

    <!-- 导入校验结果模态框 -->
    <div id="validateModal" class="modal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>配置校验未通过</h3>
                <button id="closeValidate" class="modal__close" type="button">×</button>
            </div>
            <div class="modal__body">
                <p id="validateSummary" class="validate-summary"></p>
                <ul id="validateList" class="validate-list"></ul>
            </div>
            <div class="modal__foot">
                <button id="btnForceImport" class="btn btn--ghost btn--sm" type="button" hidden>仍然导入</button>
                <button id="btnCancelImport" class="btn btn--primary btn--sm" type="button">取消导入</button>
            </div>
        </div>
    </div>

    <!-- 历史版本模态框 -->
    <div id="historyModal" class="modal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>历史版本</h3>
                <button id="closeHistory" class="modal__close" type="button">×</button>
            </div>
            <div class="modal__body">
                <p class="history-hint">每次保存到云端前会自动留存一份快照，最多保留 30 份。</p>
                <ul id="historyList" class="history-list"></ul>
            </div>
            <div class="modal__foot">
                <button id="btnCloseHistory" class="btn btn--ghost btn--sm" type="button">关闭</button>
            </div>
        </div>
    </div>

    <!-- 发布分享模态框 -->
    <div id="shareModal" class="modal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>作品已发布</h3>
                <button id="closeShare" class="modal__close" type="button">×</button>
            </div>
            <div class="modal__body">
                <p class="share-hint">作品已发布到广场，复制下面的短链即可分享给任何人。</p>
                <label class="field">
                    <span class="field__label">分享短链</span>
                    <div class="share-row">
                        <input id="shareLink" class="field__input" type="text" readonly>
                        <button id="btnCopyShare" class="btn btn--ghost btn--sm" type="button">复制</button>
                    </div>
                </label>
                <label class="field">
                    <span class="field__label">嵌入代码 <span class="field__hint">粘贴到支持 iframe 的网页中</span></span>
                    <div class="share-row">
                        <input id="shareEmbed" class="field__input" type="text" readonly>
                        <button id="btnCopyEmbed" class="btn btn--ghost btn--sm" type="button">复制</button>
                    </div>
                </label>
            </div>
            <div class="modal__foot">
                <button id="btnCloseShare" class="btn btn--primary btn--sm" type="button">完成</button>
            </div>
        </div>
    </div>

    <!-- 素材上传模态框 -->
    <div id="uploadModal" class="modal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>上传素材</h3>
                <button id="closeUpload" class="modal__close" type="button">×</button>
            </div>
            <div class="modal__body">
                <label class="field">
                    <span class="field__label">素材类型</span>
                    <select id="uploadType" class="field__input">
                        <option value="bg">背景（图片）</option>
                        <option value="sprite">立绘（图片）</option>
                        <option value="bgm">BGM（音频）</option>
                        <option value="sfx">音效（音频）</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">选择文件 <span class="field__hint" id="uploadHint"></span></span>
                    <input id="uploadFile" class="field__input" type="file">
                </label>
                <label class="field">
                    <span class="field__label">可见范围</span>
                    <select id="uploadVisibility" class="field__input">
                        <option value="0">私人使用（仅自己可见）</option>
                        <option value="1">公开使用（允许他人引用）</option>
                    </select>
                </label>
                <label class="field">
                    <span class="field__label">版权协议</span>
                    <select id="uploadLicense" class="field__input"></select>
                </label>
                <p class="upload-notice">
                    请确认你拥有该素材的版权或已获得合法授权，不得上传侵犯他人著作权、肖像权的内容。
                    选择公开使用即表示你同意其他用户在本平台内引用该素材；因上传内容引发的纠纷由上传者自行承担。
                </p>
            </div>
            <div class="modal__foot">
                <button id="btnCancelUpload" class="btn btn--ghost btn--sm" type="button">取消</button>
                <button id="btnDoUpload" class="btn btn--primary btn--sm" type="button">开始上传</button>
            </div>
        </div>
    </div>

    <!-- Toast 提示 -->
    <div id="toast" class="toast" hidden></div>

    <script>
        // 服务端注入的运行时上下文（二期：登录态与 CSRF）
        window.DRAMATOOL_CTX = <?= json_encode([
            'baseUrl'   => base_url('/'),
            'csrfToken' => \App\Core\Csrf::token(),
            'user'      => $user ? [
                'id'       => (int) $user['id'],
                'nickname' => $user['nickname'],
                'email'    => $user['email'],
            ] : null,
            // 素材上传限制（与后端 config/upload 保持一致，仅用于前端预校验与提示）
            'upload'    => [
                'maxSize'    => (int) config('upload.max_size', 0),
                'quotaBytes' => (int) config('upload.quota_user', 0),
                'quotaCount' => (int) config('upload.quota_count', 0),
                'allowed'    => config('upload.allowed', []),
                'licenses'   => config('upload.licenses', []),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
    <script src="<?= e(asset('assets/js/sprite-crop.js')) ?>"></script>
    <script src="<?= e(asset('assets/js/editor.js')) ?>"></script>
</body>
</html>
