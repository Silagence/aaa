        </div>
    </main>
    <script>
        // 服务端注入的运行时上下文（供前端读取 CSRF 令牌等）
        window.DRAMATOOL_CTX = <?= json_encode([
            'baseUrl'   => base_url('/'),
            'csrfToken' => \App\Core\Csrf::token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
</body>
</html>
