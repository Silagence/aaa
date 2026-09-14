<?php
/**
 * 播放器页面视图
 * 从 localStorage 读取配置（preview 模式），按 scenes 顺序逐节点渲染。
 * 能力：背景/立绘/对话/选项/变量/跳转、打字机、存档、历史、控制键。
 *
 * @var array|null $user 当前登录用户
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>播放器 · Dramatool</title>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/home.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/player.css')) ?>">
</head>
<body class="player-body">
    <!-- 舞台 -->
    <div class="stage" id="stage">
        <!-- 背景层 -->
        <div class="bg-layer-player" id="bgLayer" aria-hidden="true"></div>

        <!-- 立绘层 -->
        <div class="sprite-layer" id="spriteLayer" aria-hidden="true"></div>

        <!-- 对话框 -->
        <div class="dialog" id="dialog" hidden>
            <div class="dialog__speaker" id="dialogSpeaker"></div>
            <div class="dialog__text" id="dialogText"></div>
            <div class="dialog__click-hint" id="clickHint">▼</div>
        </div>

        <!-- 选项层 -->
        <div class="choices" id="choices" hidden></div>

        <!-- 顶部信息条 -->
        <div class="topinfo" id="topInfo">
            <span id="topTitle">作品</span>
            <span class="topinfo__right">
                <span id="sceneTag" class="tag"></span>
                <button class="icon-btn" id="btnAuto" title="自动播放 (A)" type="button">▶ 自动</button>
                <button class="icon-btn" id="btnSkip" title="快进 (Ctrl 按住)" type="button">⏭ 快进</button>
                <button class="icon-btn" id="btnHistory" title="历史对话 (L)" type="button">📜 历史</button>
                <button class="icon-btn" id="btnSave" title="存档 (S)" type="button">💾 存档</button>
                <button class="icon-btn" id="btnLoad" title="读档 (O)" type="button">📂 读档</button>
                <button class="icon-btn" id="btnSettings" title="设置 (Esc)" type="button">⚙ 设置</button>
            </span>
        </div>

        <!-- 剧终 -->
        <div class="ending" id="ending" hidden>
            <h1>剧 终</h1>
            <p>感谢游玩</p>
            <div class="ending__btns">
                <button class="btn btn--ghost btn--sm" id="btnRestart" type="button">重新开始</button>
                <a class="btn btn--ghost btn--sm" href="<?= e(base_url('editor')) ?>">返回编辑</a>
            </div>
        </div>

        <!-- 错误提示 -->
        <div class="errbox" id="errBox" hidden></div>
    </div>

    <!-- 历史对话模态框 -->
    <div class="modal" id="historyModal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>历史对话</h3>
                <button class="modal__close" id="closeHistory" type="button">×</button>
            </div>
            <div class="modal__body">
                <ul class="history-list" id="historyList"></ul>
            </div>
        </div>
    </div>

    <!-- 存档模态框 -->
    <div class="modal" id="saveModal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3 id="saveModalTitle">存档</h3>
                <button class="modal__close" id="closeSave" type="button">×</button>
            </div>
            <div class="modal__body">
                <ul class="save-list" id="saveList"></ul>
            </div>
            <div class="modal__foot">
                <button class="btn btn--ghost btn--sm" id="btnClearSave" type="button">清空全部</button>
            </div>
        </div>
    </div>

    <!-- 设置模态框 -->
    <div class="modal" id="settingsModal" hidden>
        <div class="modal__box">
            <div class="modal__head">
                <h3>设置</h3>
                <button class="modal__close" id="closeSettings" type="button">×</button>
            </div>
            <div class="modal__body">
                <label class="setting-row">
                    <span>文本速度</span>
                    <input id="cfgSpeed" type="range" min="5" max="80" step="1">
                    <span id="cfgSpeedVal" class="setting-val"></span>
                </label>
                <label class="setting-row">
                    <span>自动播放间隔</span>
                    <input id="cfgAuto" type="range" min="200" max="3000" step="100">
                    <span id="cfgAutoVal" class="setting-val"></span> ms
                </label>
                <label class="setting-row">
                    <span>BGM 音量</span>
                    <input id="cfgBgm" type="range" min="0" max="1" step="0.05">
                    <span id="cfgBgmVal" class="setting-val"></span>
                </label>
                <label class="setting-row">
                    <span>语音音量</span>
                    <input id="cfgVoice" type="range" min="0" max="1" step="0.05">
                    <span id="cfgVoiceVal" class="setting-val"></span>
                </label>
                <div class="setting-row">
                    <span>主题</span>
                    <select id="cfgTheme" class="field__select">
                        <option value="dark">深色</option>
                        <option value="light">浅色</option>
                        <option value="sepia">护眼</option>
                        <option value="auto">跟随系统</option>
                    </select>
                    <span class="setting-val"></span>
                </div>
                <div class="setting-row">
                    <span>已读记录</span>
                    <button class="btn btn--ghost btn--sm" id="btnClearRead" type="button">清空已读</button>
                    <span class="setting-val"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
    <script src="<?= e(asset('assets/js/player.js')) ?>"></script>
</body>
</html>
