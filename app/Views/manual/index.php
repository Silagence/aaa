<?php
/**
 * 使用手册
 *
 * 面向所有访客的公开页面，介绍从编写剧本到发布分享的完整流程。
 *
 * @var array|null $user  当前登录用户
 * @var array      $flash 一次性提示消息
 */
$title = '使用手册';
$active = 'manual';
require __DIR__ . '/../partials/app_head.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title">使用手册</h1>
        <p class="page-desc">从编写剧本到发布分享，五分钟上手 Dramatool。</p>
    </div>
</div>

<?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e($item['type']) ?>"><?= e($item['message']) ?></div>
<?php endforeach; ?>

<div class="doc">
    <nav class="doc__toc" aria-label="目录">
        <p class="doc__toc-title">目录</p>
        <ol class="doc__toc-list">
            <li><a class="link" href="#step-1">1. 快速开始</a></li>
            <li><a class="link" href="#step-2">2. 编写剧本</a></li>
            <li><a class="link" href="#step-3">3. 配置场景</a></li>
            <li><a class="link" href="#step-4">4. 预览与调试</a></li>
            <li><a class="link" href="#step-5">5. 保存与导出</a></li>
            <li><a class="link" href="#step-6">6. 发布与分享</a></li>
            <li><a class="link" href="#step-7">7. 常见问题</a></li>
        </ol>
    </nav>

    <div class="doc__body">
        <section class="doc-section" id="step-1">
            <h2 class="doc-section__title">1. 快速开始</h2>
            <p>在首页点击「开始使用」即可进入编辑器，无需安装任何软件，所有内容都在浏览器中完成。</p>
            <ol class="doc-list">
                <li>点击首页的「开始使用」按钮进入编辑器。</li>
                <li>在左侧素材库选择背景、立绘、音乐等素材。</li>
                <li>在中间编辑区书写剧本，右侧调整节点属性。</li>
                <li>点击预览按钮试玩，确认无误后保存作品。</li>
            </ol>
            <p class="doc-tip">提示：未登录也可以创作，草稿会暂存在浏览器本地；登录后可将作品保存到云端。</p>
        </section>

        <section class="doc-section" id="step-2">
            <h2 class="doc-section__title">2. 编写剧本</h2>
            <p>剧本以纯文本方式书写，输入即创作。每一行代表一个剧情节点，通过快捷语法描述对话、选项与分支。</p>
            <ul class="doc-list">
                <li><strong>对话</strong>：直接输入角色名与台词，例如「小明：你好，世界」。</li>
                <li><strong>选项</strong>：使用工具栏的「选项」按钮插入分支，每个选项可跳转到不同节点。</li>
                <li><strong>背景切换</strong>：插入「背景」节点并选择素材，即可在剧情中切换场景。</li>
                <li><strong>立绘</strong>：插入「立绘」节点，指定角色与表情。</li>
                <li><strong>变量</strong>：插入「变量」节点记录好感度、道具等状态，用于条件分支。</li>
            </ul>
            <p class="doc-tip">提示：编辑器支持撤销 / 重做，误操作时可用快捷键回退。</p>
        </section>

        <section class="doc-section" id="step-3">
            <h2 class="doc-section__title">3. 配置场景</h2>
            <p>选中任意节点后，右侧属性面板会显示该节点的可配置项，包括背景、立绘位置、音乐与音效、转场效果等。</p>
            <ul class="doc-list">
                <li>背景与立绘均来自素材库，可上传自己的图片素材。</li>
                <li>上传素材时需选择版权许可（原创 / CC BY / CC0 / CC BY-SA）。</li>
                <li>音乐与音效支持循环播放与音量调节。</li>
            </ul>
        </section>

        <section class="doc-section" id="step-4">
            <h2 class="doc-section__title">4. 预览与调试</h2>
            <p>点击编辑器顶部的「预览」按钮即可在播放器中试玩当前剧本，实时查看分支走向与素材效果。</p>
            <ul class="doc-list">
                <li>播放器支持自动播放、快进与回退。</li>
                <li>可在预览中检查变量是否正确、分支是否可达。</li>
            </ul>
        </section>

        <section class="doc-section" id="step-5">
            <h2 class="doc-section__title">5. 保存与导出</h2>
            <p>作品会保存到你的账号下，可在「我的作品」中随时继续编辑。你也可以导出配置文件，用于备份或迁移。</p>
            <ul class="doc-list">
                <li><strong>保存</strong>：登录后点击保存，作品同步到云端。</li>
                <li><strong>导出</strong>：导出为配置文件，便于本地留存。</li>
                <li><strong>导入</strong>：在编辑器中导入配置文件，恢复剧本内容。</li>
                <li><strong>历史版本</strong>：每次保存都会生成版本记录，可随时回滚。</li>
            </ul>
        </section>

        <section class="doc-section" id="step-6">
            <h2 class="doc-section__title">6. 发布与分享</h2>
            <p>作品完成后可发布到「作品广场」，让其他玩家试玩、点赞与收藏。</p>
            <ul class="doc-list">
                <li>在「我的作品」中点击发布，作品将出现在作品广场。</li>
                <li>每个作品都有独立分享链接，可直接分享给好友。</li>
                <li>支持 iframe 嵌入，可把作品放到自己的博客或主页。</li>
                <li>其他玩家可以评论、点赞、收藏，也可以举报违规内容。</li>
            </ul>
        </section>

        <section class="doc-section" id="step-7">
            <h2 class="doc-section__title">7. 常见问题</h2>
            <dl class="doc-faq">
                <dt>未登录可以创作吗？</dt>
                <dd>可以。未登录时草稿保存在浏览器本地，登录后可将作品保存到云端。</dd>

                <dt>如何上传自己的素材？</dt>
                <dd>在编辑器左侧素材库点击上传，选择图片并确认版权许可即可。</dd>

                <dt>作品可以修改吗？</dt>
                <dd>可以。在「我的作品」中打开作品继续编辑，保存后会生成新的历史版本。</dd>

                <dt>如何删除作品？</dt>
                <dd>在「我的作品」中找到对应作品，点击删除即可，删除后不可恢复。</dd>
            </dl>
        </section>

        <div class="doc__actions">
            <a class="btn btn--primary" href="<?= e(base_url('editor')) ?>">开始创作</a>
            <a class="btn btn--ghost" href="<?= e(base_url('about')) ?>">了解网站</a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/app_foot.php'; ?>
