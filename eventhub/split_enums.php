<?php
$content = file_get_contents('d:\next-technical-assessment\eventhub\apps\core-api\app\Enums\OrderEnums.php');
preg_match_all('/enum (\w+): string\s*\{.*?\}/s', $content, $matches, PREG_SET_ORDER);
foreach ($matches as $match) {
    $enumName = $match[1];
    $enumCode = "<?php\n\nnamespace App\Enums;\n\n" . $match[0] . "\n";
    file_put_contents('d:\next-technical-assessment\eventhub\apps\core-api\app\Enums\\' . $enumName . '.php', $enumCode);
}
unlink('d:\next-technical-assessment\eventhub\apps\core-api\app\Enums\OrderEnums.php');
