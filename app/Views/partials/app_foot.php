    </main>

    <footer class="footer">
        <div class="footer__inner">
            <span>© 2026 Dramatool AVG Editor</span>
        </div>
    </footer>

    <script src="<?= e(asset('assets/js/theme.js')) ?>"></script>
    <script>
        // 顶栏主题切换按钮（深色 → 浅色 → 护眼 → 跟随系统）
        (function () {
            var btn = document.getElementById('themeToggle');
            if (!btn || !window.DramatoolTheme) return;
            var names = { dark: '深色', light: '浅色', sepia: '护眼', auto: '跟随系统' };
            btn.addEventListener('click', function () {
                window.DramatoolTheme.cycle();
                btn.textContent = '主题：' + (names[window.DramatoolTheme.get()] || '');
            });
            btn.textContent = '主题：' + (names[window.DramatoolTheme.get()] || '');
        })();
    </script>
</body>
</html>
