<?php
/**
 * 敏感词过滤服务
 *
 * 词库来自 app/blockword.txt，纯文本格式，每行一个词条，
 * 以 # 开头的行视为注释、空行忽略。
 *
 * 词条按 PCRE 正则处理，直接匹配原始文本，因此支持词库中
 * 常见的转义与模式写法：
 *   - \n \r \t 等转义序列匹配对应的控制字符
 *   - \pP* 表示「任意标点零次或多次」，用于容忍词中插入的标点
 * 词库中的反斜杠通常被转义过一层（如 \\n、\\pP*），
 * 载入时会先反转义一层还原为 \n、\pP* 再编译。
 *
 * 词库较大时首次使用一次性编译并缓存，避免重复解析。
 */

declare(strict_types=1);

namespace App\Services;

class BlockWord
{
    /** 词库文件路径 */
    private const FILE = __DIR__ . '/../blockword.txt';

    /** @var array<int, string>|null 已编译的正则列表缓存 */
    private static ?array $patterns = null;

    /**
     * 检测文本是否命中敏感词
     *
     * @return bool 命中返回 true
     */
    public static function hit(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        self::load();
        foreach (self::$patterns ?? [] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * 校验文本，命中敏感词时返回错误提示；通过则返回 null
     *
     * 出于安全考虑，提示中不暴露命中的具体词条。
     */
    public static function validate(string $text, string $field = '内容'): ?string
    {
        if (!self::hit($text)) {
            return null;
        }
        return $field . '包含敏感词，请修改后重试';
    }

    /**
     * 载入并编译词库（仅首次执行）
     */
    private static function load(): void
    {
        if (self::$patterns !== null) {
            return;
        }

        self::$patterns = [];

        if (!is_file(self::FILE)) {
            return;
        }
        $raw = file_get_contents(self::FILE);
        if ($raw === false || $raw === '') {
            return;
        }

        // 去掉 UTF-8 BOM，避免首个词条被污染
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw;

        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $word = trim($line);
            // 跳过空行与注释行
            if ($word === '' || str_starts_with($word, '#')) {
                continue;
            }

            $pattern = self::compile($word);
            if ($pattern === null || isset($seen[$pattern])) {
                continue;
            }
            $seen[$pattern] = true;
            self::$patterns[] = $pattern;
        }
    }

    /**
     * 将词条编译为可安全执行的正则
     *
     * 词条本身即正则片段，这里先反转义一层（\\n -> \n），
     * 再包裹定界符与修饰符，并校验其合法性；
     * 非法词条直接跳过，避免影响其余词条。
     */
    private static function compile(string $word): ?string
    {
        // 反转义一层：\\n -> \n，\\pP* -> \pP*
        $body = str_replace('\\\\', '\\', $word);
        if ($body === '') {
            return null;
        }
        // 词条中若含定界符 / 需转义，避免破坏正则结构
        $body = str_replace('/', '\/', $body);
        $pattern = '/(' . $body . ')/iu';

        // 用 @ 抑制编译告警，非法正则返回 false
        if (@preg_match($pattern, '') === false) {
            return null;
        }
        return $pattern;
    }
}
