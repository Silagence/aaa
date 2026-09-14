<?php
/**
 * 精简嵌入播放页（/embed/{code}，供 iframe 引用）
 *
 * 仅保留舞台与对话框，隐藏顶部信息条与各类模态框，适合嵌入第三方页面。
 *
 * @var array|null $work 公开作品，不存在时为 null
 * @var string     $code 短链码
 */
$title = $work !== null ? (string) $work['title'] : '作品不存在';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · Dramatool</title>
    <script><?= \App\Core\Theme::foucScript() ?></script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/player.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/embed.css')) ?>">
</head>
<body class="embed-body">
    <?php if ($work === null): ?>
        <div class="embed-empty">作品不存在或未发布</div>
    <?php else: ?>
        <div class="stage" id="stage">
            <div class="bg-layer-player" id="bgLayer" aria-hidden="true"></div>
            <div class="sprite-layer" id="spriteLayer" aria-hidden="true"></div>

            <div class="dialog" id="dialog" hidden>
                <div class="dialog__speaker" id="dialogSpeaker"></div>
                <div class="dialog__text" id="dialogText"></div>
                <div class="dialog__click-hint" id="clickHint">▼</div>
            </div>

            <div class="choices" id="choices" hidden></div>

            <div class="topinfo" id="topInfo">
                <span id="topTitle"><?= e($work['title']) ?></span>
                <span class="topinfo__right">
                    <span id="sceneTag" class="tag"></span>
                    <button class="icon-btn" id="btnAuto" title="自动播放" type="button">▶ 自动</button>
                    <button class="icon-btn" id="btnSkip" title="快进" type="button">⏭ 快进</button>
                    <button class="icon-btn" id="btnHistory" title="历史对话" type="button">📜 历史</button>
                    <button class="icon-btn" id="btnSettings" title="设置" type="button">⚙ 设置</button>
                </span>
            </div>

            <div class="ending" id="ending" hidden>
                <h1>剧 终</h1>
                <p>感谢游玩</p>
                <div class="ending__btns">
                    <button class="btn btn--ghost btn--sm" id="btnRestart" type="button">重新开始</button>
                    <a class="btn btn--ghost btn--sm" href="<?= e(base_url('w/' . $code)) ?>"
                       target="_blank" rel="noopener">查看作品详情</a>
                </div>
            </div>

            <div class="errbox" id="errBox" hidden></div>
        </div>

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
                </div>
            </div>
        </div>

        <div id="toast" class="toast" hidden></div>

        <script>
            window.DRAMATOOL_CTX = <?= json_encode([
                'baseUrl'   => base_url('/'),
                'csrfToken' => \App\Core\Csrf::token(),
                'user'      => null,
                'embed'     => true,
                'workId'    => (int) $work['id'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        </script>
        <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
        <script src="<?= e(asset('assets/js/player.js')) ?>"></script>
    <?php endif; ?>
</body>
</html>
